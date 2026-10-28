<?php

namespace App\Alerts\Intents;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\InboxCandidate;
use App\Alerts\Triage;
use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Jobs\PlanAlertReply;
use App\Jobs\ScheduleTask;
use App\Models\AgendaTask;
use App\Models\InboxItem;
use App\Models\SlackTriageDecision;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Puts each new message on an intent. An intent is shown as an alert: pending,
 * resolved or dismissed. A message joins one that already exists whenever the
 * ask is the same; a new alert is opened only when it is not, and only when
 * the person actually has to answer.
 */
class IntentAssigner
{
    /** Closed intents stay matchable for this long, so a later channel can reopen them. */
    private const MEMORY_DAYS = 30;

    public function __construct(
        private IntentClassifier $classifier,
        private RuleIntentClassifier $rules,
        private SlackThreadReader $threads,
        private SlackTriage $triage,
        private SlackAutonomy $autonomy,
    ) {}

    /**
     * @param  list<array{id: string, from: ?string, text: string, at: string, source?: string}>  $earlier  Same conversation, earlier in this check, oldest first.
     */
    public function assign(User $user, InboxCandidate $candidate, array $earlier = [], bool $byRules = false): ?InboxItem
    {
        $existing = $this->holding($user, $candidate->externalId);

        if ($existing !== null) {
            return $existing;
        }

        $intents = $this->intents($user);
        $context = $this->context($user);
        $state = null;

        if ($candidate->source === AlertSource::Slack) {
            $state = $this->threads->read($user, $candidate);
            $context = trim($context."\n\n".$state->prompt());
        }

        $decision = ($byRules ? $this->rules : $this->classifier)->classify(
            $user,
            $candidate,
            $intents->map(fn (InboxItem $item): array => $this->describe($item))->all(),
            $context,
        );

        $row = null;

        if ($state !== null) {
            $profile = $user->slackAttentionProfile;
            $decision = $this->triage->adjust($decision, $state, $profile?->signals);
            $decision = $this->triage->recover($user, $candidate, $decision, $state);
            $row = $this->triage->record($user, $candidate, $decision, $state, $profile);
        }

        $intent = $decision->intentId !== null ? $intents->firstWhere('id', $decision->intentId) : null;

        if ($intent !== null && $this->sameConversation($intent, $candidate) && $this->closed($intent)) {
            return null;
        }

        if ($intent !== null && $this->closed($intent)) {
            return $decision->shown() ? $this->remember($row, $this->reopen($intent, $candidate, $decision)) : null;
        }

        if ($intent !== null) {
            return $this->remember($row, $this->append($intent, $candidate, $decision, $earlier));
        }

        if (! $decision->shown()) {
            return null;
        }

        return $this->remember($row, $this->open($user, $candidate, $decision, $earlier));
    }

    /**
     * Opens an alert from a decision that was already taken, without asking
     * the model again. Used when an unanswered question in her channel is
     * promoted hours later.
     */
    public function raise(User $user, InboxCandidate $candidate, IntentDecision $decision): ?InboxItem
    {
        return $this->open($user, $candidate, $decision, []);
    }

    /**
     * @return Collection<int, InboxItem>
     */
    private function intents(User $user): Collection
    {
        return $user->inboxItems()
            ->whereIn('source', [AlertSource::Gmail, AlertSource::Slack, AlertSource::Jira])
            ->whereIn('status', [AlertStatus::Pending, AlertStatus::Resolved, AlertStatus::Dismissed])
            ->where('received_at', '>=', now()->subDays(self::MEMORY_DAYS))
            ->limit(40)
            ->get();
    }

    private function holding(User $user, string $externalId): ?InboxItem
    {
        return $user->inboxItems()
            ->where(fn ($items) => $items->where('external_id', $externalId)->orWhereJsonContains('payload->message_ids', $externalId))
            ->first();
    }

    private function context(User $user): string
    {
        $parts = [];
        $work = $user->workContext;

        if ($work !== null && $work->usable()) {
            $parts[] = $work->summary;
        }

        $attention = $user->slackAttentionProfile;

        if ($attention !== null && filled($attention->body)) {
            $parts[] = "Política de atención en Slack, aprendida de cómo ha respondido:\n".$attention->body;
        }

        $lessons = $user->intentLessons()->latest('id')->limit(8)->pluck('body');

        if ($lessons->isNotEmpty()) {
            $parts[] = "Aprendido al resolver avisos anteriores:\n".$lessons->implode("\n");
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return array{id: int, status: string, goal: string, who: string, source: string, threads: list<string>, excerpt: string}
     */
    private function describe(InboxItem $item): array
    {
        return [
            'id' => $item->id,
            'status' => $item->status->value,
            'goal' => (string) ($item->payload['goal'] ?? $item->title),
            'who' => (string) $item->actor_name,
            'source' => $item->source->label(),
            'threads' => $item->threads(),
            'excerpt' => Str::limit((string) $item->body, 280),
        ];
    }

    private function sameConversation(InboxItem $intent, InboxCandidate $candidate): bool
    {
        return $candidate->threadKey !== null && in_array($candidate->threadKey, $intent->threads(), true);
    }

    private function closed(InboxItem $intent): bool
    {
        return in_array($intent->status, [AlertStatus::Resolved, AlertStatus::Dismissed], true);
    }

    /**
     * @param  list<array{id: string, from: ?string, text: string, at: string, source?: string}>  $earlier
     */
    private function open(User $user, InboxCandidate $candidate, IntentDecision $decision, array $earlier): InboxItem
    {
        $messages = [...$earlier, InboxItem::messageOf($candidate)];
        $item = $user->inboxItems()->create([
            'source' => $candidate->source,
            'external_id' => $candidate->externalId,
            'thread_key' => $candidate->threadKey,
            'external_url' => $candidate->url,
            'actor_name' => $candidate->from?->label(),
            'title' => $candidate->subject,
            'body' => mb_substr($candidate->excerpt, 0, 1000),
            'why' => $decision->reason,
            'relevance' => $decision->relevance,
            'status' => AlertStatus::Pending,
            'triage' => Triage::Important,
            'planning' => true,
            'payload' => [
                'account' => $candidate->account,
                'from' => $candidate->from?->toArray(),
                'goal' => $decision->goal,
                'kind' => $candidate->labels[0] ?? null,
                'candidate' => $candidate->toArray(),
                'messages' => $messages,
                'message_ids' => array_column($messages, 'id'),
                'threads' => array_values(array_filter([$candidate->threadKey])),
            ],
            'received_at' => $candidate->receivedAt,
        ]);

        InboxItemCreated::dispatch($item);
        $this->follow($item, $decision);

        return $item;
    }

    /**
     * @param  list<array{id: string, from: ?string, text: string, at: string, source?: string}>  $earlier
     */
    private function append(InboxItem $intent, InboxCandidate $candidate, IntentDecision $decision, array $earlier): InboxItem
    {
        $known = $intent->payload['message_ids'] ?? [$intent->external_id];
        $incoming = array_values(array_filter(
            [...$earlier, InboxItem::messageOf($candidate)],
            fn (array $message): bool => ! in_array($message['id'], $known, true),
        ));

        if ($incoming === []) {
            return $intent;
        }

        $payload = $intent->payload ?? [];
        $payload['messages'] = array_slice([...($payload['messages'] ?? $intent->messages()), ...$incoming], -InboxItem::MAX_MESSAGES);
        $payload['message_ids'] = array_slice([...$known, ...array_column($incoming, 'id')], -100);
        $payload['threads'] = array_values(array_unique(array_filter([...$intent->threads(), $candidate->threadKey])));
        $payload['from'] = $candidate->from?->toArray() ?? ($payload['from'] ?? null);
        $payload['candidate'] = $candidate->toArray();

        if (! $decision->shown()) {
            $intent->update(['payload' => $payload]);

            return $intent;
        }

        $intent->fill([
            'source' => $candidate->source,
            'external_id' => $candidate->externalId,
            'thread_key' => $candidate->threadKey ?? $intent->thread_key,
            'external_url' => $candidate->url ?? $intent->external_url,
            'actor_name' => $candidate->from?->label() ?? $intent->actor_name,
            'title' => $candidate->subject,
            'body' => mb_substr($candidate->excerpt, 0, 1000),
            'why' => $decision->reason,
            'relevance' => $decision->relevance,
            'received_at' => $candidate->receivedAt,
            'payload' => $payload,
            'planning' => true,
        ]);
        $intent->save();

        InboxItemUpdated::dispatch($intent);
        $this->follow($intent, $decision);

        return $intent;
    }

    private function reopen(InboxItem $intent, InboxCandidate $candidate, IntentDecision $decision): InboxItem
    {
        $payload = $intent->payload ?? [];
        $message = InboxItem::messageOf($candidate);
        $payload['messages'] = [$message];
        $payload['message_ids'] = array_slice([...($payload['message_ids'] ?? []), $candidate->externalId], -100);
        $payload['threads'] = array_values(array_unique(array_filter([...$intent->threads(), $candidate->threadKey])));
        $payload['from'] = $candidate->from?->toArray();
        $payload['candidate'] = $candidate->toArray();
        $payload['goal'] = $payload['goal'] ?? $decision->goal;

        $intent->fill([
            'source' => $candidate->source,
            'external_id' => $candidate->externalId,
            'thread_key' => $candidate->threadKey ?? $intent->thread_key,
            'external_url' => $candidate->url ?? $intent->external_url,
            'actor_name' => $candidate->from?->label() ?? $intent->actor_name,
            'title' => $candidate->subject,
            'body' => mb_substr($candidate->excerpt, 0, 1000),
            'why' => $decision->reason,
            'relevance' => $decision->relevance,
            'status' => AlertStatus::Pending,
            'triage' => Triage::Important,
            'resolution' => null,
            'resolved_at' => null,
            'proposal' => null,
            'planning' => true,
            'received_at' => $candidate->receivedAt,
            'payload' => $payload,
        ]);
        $intent->save();

        InboxItemUpdated::dispatch($intent);
        $this->follow($intent, $decision);

        return $intent;
    }

    private function follow(InboxItem $item, IntentDecision $decision): void
    {
        if ($decision->action !== null && $this->autonomy->level($item->user, $decision->action) === AutonomyLevel::Watch) {
            $item->update([
                'proposal' => [
                    'summary' => $decision->reason,
                    'reply' => null,
                    'other_actions' => [],
                    'question' => null,
                    'task' => null,
                ],
                'planning' => false,
            ]);

            return;
        }

        if ($decision->action === SlackAction::Task) {
            $item->update([
                'proposal' => [
                    'summary' => $decision->reason,
                    'reply' => null,
                    'other_actions' => [],
                    'question' => null,
                    'task' => null,
                ],
                'planning' => false,
            ]);
            $this->agenda($item, $decision);

            return;
        }

        PlanAlertReply::dispatch($item);
    }

    private function agenda(InboxItem $item, IntentDecision $decision): void
    {
        if (AgendaTask::query()->where('inbox_item_id', $item->id)->exists()) {
            return;
        }

        $task = $item->user->agendaTasks()->create([
            'inbox_item_id' => $item->id,
            'origin' => 'alert',
            'title' => $decision->goal,
            'context' => trim("{$item->actor_name}: {$decision->reason}"),
        ]);

        ScheduleTask::dispatch($task, 30, null, []);
    }

    private function remember(?SlackTriageDecision $row, InboxItem $item): InboxItem
    {
        $row?->update(['inbox_item_id' => $item->id]);

        return $item;
    }
}
