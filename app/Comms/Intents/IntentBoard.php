<?php

namespace App\Comms\Intents;

use App\Comms\Events\Source;
use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;

/**
 * The three groups on the resting screen.
 */
class IntentBoard
{
    public function __construct(private MessageUrl $urls) {}

    /**
     * @return array{
     *     waiting_on_me: list<array<string, mixed>>,
     *     waiting_on_others: list<array<string, mixed>>,
     *     quiet: list<array<string, mixed>>
     * }
     */
    public function for(User $user): array
    {
        $selfId = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id');
        $groups = [
            'waiting_on_me' => [],
            'waiting_on_others' => [],
            'quiet' => [],
        ];

        $intents = CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('state', 'open')
            ->with(['participants.person', 'links.message'])
            ->orderByDesc('importance')
            ->orderByDesc('last_activity_at')
            ->get();

        [$proposals, $notifications] = $this->linkedWork($user, $intents->pluck('id')->all());

        foreach ($intents as $intent) {
            $group = $this->group($intent, $selfId);
            $groups[$group][] = $this->row(
                $intent,
                $group,
                $selfId,
                $proposals[$intent->id] ?? null,
                $notifications[$intent->id] ?? null,
            );
        }

        return $groups;
    }

    private function group(CommsIntent $intent, mixed $selfId): string
    {
        $activity = $intent->last_activity_at;
        $quietAfter = (int) config('comms.quiet_after_days', 7);

        if ($activity === null || $activity->lt(now()->subDays($quietAfter))) {
            return 'quiet';
        }

        $requesters = $intent->participants->where('role', 'requester')->pluck('person_id');
        $responders = $intent->participants->where('role', 'responder')->pluck('person_id');
        $possible = $intent->participants->where('role', 'possible_responder')->pluck('person_id');
        $userResponds = $selfId !== null && ($responders->contains($selfId) || $possible->contains($selfId));
        $someoneWaiting = $requesters->contains(fn (mixed $id): bool => $id !== $selfId);

        if ($userResponds && $someoneWaiting) {
            return 'waiting_on_me';
        }

        $userAsked = $selfId !== null && $requesters->contains($selfId);
        $someoneElse = $responders->contains(fn (mixed $id): bool => $id !== $selfId)
            || $possible->contains(fn (mixed $id): bool => $id !== $selfId);

        if ($userAsked && $someoneElse) {
            return 'waiting_on_others';
        }

        return 'quiet';
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        CommsIntent $intent,
        string $group,
        mixed $selfId,
        ?CommsProposal $proposal,
        ?CommsNotification $notification,
    ): array {
        $sorted = $intent->links
            ->filter(fn ($link): bool => $link->message !== null)
            ->sortBy(fn ($link): int => $link->message?->occurred_at?->getTimestamp() ?? 0)
            ->values();
        $earliest = $sorted->first();
        $latest = $sorted->last();
        $uncertain = $intent->links->first(fn ($link): bool => $link->uncertain && $link->message !== null);
        $score = (int) $intent->importance;
        $source = $latest?->message?->source;
        $activityAt = $intent->last_activity_at ?? $latest?->message?->occurred_at;
        $openedAt = $earliest?->message?->occurred_at ?? $intent->created_at;

        return [
            'key' => $intent->key,
            'what' => $intent->what,
            'who' => $this->who($intent, $group, $selfId),
            'deadline' => $intent->deadline?->toDateString(),
            'last_change' => $intent->last_change_summary,
            'source' => $source?->value,
            'source_label' => $this->sourceLabel($source),
            'source_url' => $latest?->message !== null ? $this->urls->for($latest->message) : null,
            'activity_at' => $activityAt?->toIso8601String(),
            'opened_at' => $openedAt?->toIso8601String(),
            'urgent' => (bool) ($intent->flags['urgent'] ?? false),
            'messages_count' => $sorted->count(),
            'alert_id' => $notification !== null ? -$notification->id : null,
            'proposal_ready' => $proposal !== null && in_array($proposal->status, [ProposalStatus::Pending, ProposalStatus::Edited], true),
            'importance' => $this->level($score),
            'uncertain' => $uncertain !== null || (bool) ($intent->flags['uncertain'] ?? false),
            'message' => $uncertain?->message === null ? null : [
                'source' => $uncertain->message->source->value,
                'external_id' => $uncertain->message->external_id,
                'text' => mb_substr($uncertain->message->text, 0, 280),
            ],
        ];
    }

    /**
     * @param  list<int>  $intentIds
     * @return array{0: array<int, CommsProposal>, 1: array<int, CommsNotification>}
     */
    private function linkedWork(User $user, array $intentIds): array
    {
        if ($intentIds === []) {
            return [[], []];
        }

        $proposals = CommsProposal::query()
            ->whereBelongsTo($user)
            ->whereIn('intent_id', $intentIds)
            ->orderByDesc('id')
            ->get(['id', 'intent_id', 'status']);

        $proposalsByIntent = $proposals->unique('intent_id')->keyBy('intent_id')->all();
        $intentByProposal = $proposals->pluck('intent_id', 'id');
        $intentByQuestion = CommsQuestion::query()
            ->whereBelongsTo($user)
            ->whereIn('intent_id', $intentIds)
            ->pluck('intent_id', 'id');

        $notifications = CommsNotification::query()
            ->whereBelongsTo($user)
            ->whereNull('dismissed_at')
            ->where(function ($query) use ($intentIds, $intentByProposal, $intentByQuestion): void {
                $query->where(fn ($sub) => $sub->where('subject_type', 'intent')->whereIn('subject_id', $intentIds));

                if ($intentByProposal->isNotEmpty()) {
                    $query->orWhere(fn ($sub) => $sub->where('subject_type', 'proposal')->whereIn('subject_id', $intentByProposal->keys()->all()));
                }

                if ($intentByQuestion->isNotEmpty()) {
                    $query->orWhere(fn ($sub) => $sub->where('subject_type', 'question')->whereIn('subject_id', $intentByQuestion->keys()->all()));
                }
            })
            ->orderByDesc('id')
            ->get(['id', 'kind', 'subject_type', 'subject_id']);

        $notificationsByIntent = [];

        foreach ($notifications as $notification) {
            $intentId = match ($notification->subject_type) {
                'intent' => $notification->subject_id,
                'proposal' => $intentByProposal->get($notification->subject_id),
                'question' => $intentByQuestion->get($notification->subject_id),
                default => null,
            };

            if ($intentId === null) {
                continue;
            }

            $existing = $notificationsByIntent[$intentId] ?? null;

            if ($existing === null || ($existing->kind !== 'proposal' && $notification->kind === 'proposal')) {
                $notificationsByIntent[$intentId] = $notification;
            }
        }

        return [$proposalsByIntent, $notificationsByIntent];
    }

    private function sourceLabel(?Source $source): ?string
    {
        return match ($source) {
            Source::Slack => 'Slack',
            Source::Gmail => 'Gmail',
            Source::Jira => 'Jira',
            Source::Notion => 'Notion',
            Source::Calendar => 'Calendar',
            Source::Drive => 'Drive',
            Source::GitHub => 'GitHub',
            default => null,
        };
    }

    private function who(CommsIntent $intent, string $group, mixed $selfId): string
    {
        $role = $group === 'waiting_on_others' ? 'responder' : 'requester';
        $names = $intent->participants
            ->where('role', $role)
            ->reject(fn ($participant): bool => $participant->person_id === $selfId)
            ->map(fn ($participant): string => (string) $participant->person->display_name)
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            $names = $intent->participants
                ->reject(fn ($participant): bool => $participant->person_id === $selfId)
                ->map(fn ($participant): string => (string) $participant->person->display_name)
                ->filter()
                ->unique()
                ->values();
        }

        return $names->implode(', ');
    }

    private function level(int $score): string
    {
        if ($score >= 50) {
            return 'alta';
        }

        if ($score >= 20) {
            return 'media';
        }

        return 'baja';
    }
}
