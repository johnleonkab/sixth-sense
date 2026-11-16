<?php

namespace App\Comms\Nightly;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Intents\IntentStateMachine;
use App\Comms\Linking\AgreedOperation;
use App\Comms\Linking\AgreementStatus;
use App\Comms\Linking\Checks;
use App\Comms\Linking\CriticVerdict;
use App\Comms\Linking\Schemas;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsReconcileChange;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Applies the night's automatic fixes when the reconciler flag is on.
 * Merges, splits, topic merges, and a relink of a certain link stay proposed
 * until someone accepts them. Nothing here runs while the flag is off.
 */
class ReconcileApplier
{
    public function __construct(
        private IntentStateMachine $machine,
        private EventLog $log,
        private Checks $checks,
        private ModelRouter $router,
    ) {}

    public function apply(User $user, ?CarbonImmutable $day = null): int
    {
        if (! config('comms.linking.reconciler')) {
            return 0;
        }

        $day = $this->day($user, $day);
        $applied = 0;
        $changes = CommsReconcileChange::query()
            ->whereBelongsTo($user)
            ->whereDate('run_date', $day->toDateString())
            ->where('disposition', 'proposed')
            ->orderBy('id')
            ->get();

        foreach ($changes as $change) {
            if ($this->applyOne($user, $day, $change)) {
                $applied++;
            }
        }

        return $applied;
    }

    public function accept(CommsReconcileChange $change): bool
    {
        if ($change->disposition !== 'proposed' || $change->op !== 'merge') {
            return false;
        }

        $change->loadMissing('user');
        $user = $change->user;
        $payload = is_array($change->payload) ? $change->payload : [];

        if (! $user instanceof User) {
            return false;
        }

        $target = $this->intent($user, $payload['a'] ?? null);
        $source = $this->intent($user, $payload['b'] ?? null);

        if ($target === null || $source === null || $target->id === $source->id) {
            return false;
        }

        $event = $this->record($user, $change, [
            'action' => 'merge',
            'intent_key' => $source->key,
            'target_key' => $target->key,
        ], 'merge');
        $this->machine->correct($event);

        if ($source->refresh()->state !== 'settled' || $source->settle_reason !== 'merged') {
            return false;
        }

        $this->finish($change, $event, 'accepted', []);

        return true;
    }

    public function undo(CommsReconcileChange $change): bool
    {
        if (! in_array($change->disposition, ['applied', 'accepted'], true)) {
            return false;
        }

        $change->loadMissing('user');
        $user = $change->user;
        $prior = is_array($change->payload) ? ($change->payload['prior'] ?? null) : null;

        if (! $user instanceof User || ! is_array($prior)) {
            return false;
        }

        $links = is_array($prior['links'] ?? null) ? $prior['links'] : [];
        $reopen = is_string($prior['reopen_key'] ?? null) ? $prior['reopen_key'] : '';

        if ($links === [] && $reopen === '') {
            return false;
        }

        if ($links !== []) {
            $intent = $this->intent($user, $links[0]['intent_id'] ?? null);

            if ($intent === null) {
                return false;
            }

            $event = $this->record($user, $change, [
                'action' => 'restore_link',
                'intent_key' => $intent->key,
                'links' => $links,
            ], 'undo');
            $this->machine->correct($event);

            foreach ($links as $row) {
                if (! is_array($row)) {
                    return false;
                }

                $link = CommsConversationLink::query()->whereBelongsTo($user)->whereKey((int) ($row['id'] ?? 0))->first();

                if ($link === null || $link->intent_id !== (int) $row['intent_id'] || $link->uncertain !== (bool) ($row['uncertain'] ?? false)) {
                    return false;
                }
            }
        }

        if ($reopen !== '') {
            $event = $this->record($user, $change, [
                'action' => 'still_open',
                'intent_key' => $reopen,
            ], 'reopen');
            $this->machine->correct($event);
        }

        $change->disposition = 'undone';
        $change->save();

        return true;
    }

    private function applyOne(User $user, CarbonImmutable $day, CommsReconcileChange $change): bool
    {
        $auto = config('comms.reconcile.auto');

        if (! is_array($auto)) {
            return false;
        }

        $payload = is_array($change->payload) ? $change->payload : [];

        return match ($change->op) {
            'confirm_link' => in_array('confirm_link', $auto, true) && $this->confirm($user, $change, $payload),
            'relink' => in_array('relink_uncertain', $auto, true) && $this->relink($user, $change, $payload, uncertainOnly: true),
            'settle' => in_array('settle_quoted', $auto, true) && $this->settle($user, $day, $change, $payload),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirm(User $user, CommsReconcileChange $change, array $payload): bool
    {
        $intent = $this->intent($user, $payload['intent_id'] ?? null);
        $links = $intent instanceof CommsIntent ? $this->links($user, $intent, $payload) : null;

        if (! $intent instanceof CommsIntent || ! $links instanceof Collection || $links->isEmpty()) {
            return false;
        }

        if ($links->contains(fn (CommsConversationLink $link): bool => ! $link->uncertain)) {
            return false;
        }

        $event = $this->record($user, $change, [
            'action' => 'confirm_link',
            'intent_key' => $intent->key,
            'link_ids' => $links->pluck('id')->all(),
        ], 'confirm');
        $this->machine->correct($event);

        $stillUncertain = CommsConversationLink::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $links->pluck('id')->all())
            ->where('uncertain', true)
            ->exists();

        if ($stillUncertain) {
            return false;
        }

        $this->finish($change, $event, 'applied', ['links' => $this->priorLinks($links)]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function relink(User $user, CommsReconcileChange $change, array $payload, bool $uncertainOnly): bool
    {
        $from = $this->intent($user, $payload['from_intent'] ?? null);
        $links = $from instanceof CommsIntent ? $this->links($user, $from, $payload) : null;

        if (! $from instanceof CommsIntent || ! $links instanceof Collection || $links->isEmpty()) {
            return false;
        }

        if ($uncertainOnly && $links->contains(fn (CommsConversationLink $link): bool => ! $link->uncertain)) {
            return false;
        }

        $to = (string) ($payload['to_intent'] ?? '');
        $createKey = null;
        $targetKey = $to;

        if ($to === 'new') {
            $createKey = 'reconcile:'.$change->id.':new';
            $targetKey = $createKey;
        } else {
            $target = $this->intent($user, $to);

            if ($target === null || $target->id === $from->id) {
                return false;
            }

            $targetKey = $target->key;
        }

        $messages = [];

        foreach ($links as $link) {
            $message = $link->message;

            if (! $message instanceof CommsMessage || ! is_string($message->external_id) || $message->external_id === '') {
                return false;
            }

            $messages[] = [
                'source' => $message->source->value,
                'external_id' => $message->external_id,
            ];
        }

        $quote = is_string($payload['quote'] ?? null) ? $payload['quote'] : (string) $change->quote;
        $event = $this->record($user, $change, [
            'action' => 'relink',
            'intent_key' => $from->key,
            'target_key' => $targetKey,
            'create_key' => $createKey,
            'what' => $quote !== '' ? $quote : $from->what,
            'messages' => $messages,
        ], 'relink');
        $this->machine->correct($event);
        $target = $this->intent($user, $targetKey);

        if ($target === null) {
            return false;
        }

        $moved = CommsConversationLink::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $links->pluck('id')->all())
            ->get();

        if ($moved->count() !== $links->count() || $moved->contains(fn (CommsConversationLink $link): bool => $link->intent_id !== $target->id || $link->uncertain)) {
            return false;
        }

        $this->finish($change, $event, 'applied', ['links' => $this->priorLinks($links)]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function settle(User $user, CarbonImmutable $day, CommsReconcileChange $change, array $payload): bool
    {
        $intent = $this->intent($user, $payload['intent_id'] ?? null);
        $quote = $payload['quote'] ?? $change->quote;
        $reason = $this->settleReason($payload);

        if (! $intent instanceof CommsIntent || $intent->state !== 'open' || ! is_string($quote) || trim($quote) === '' || $reason === null) {
            return false;
        }

        $messageIds = $this->dayMessageIds($user, $day, $intent);

        if ($messageIds === []) {
            return false;
        }

        $operation = new AgreedOperation([
            'op' => 'settle',
            'intent_id' => (string) $intent->id,
            'quote' => $quote,
            'settle_reason' => $reason,
        ], AgreementStatus::Agreed);
        $segment = new CommsSegment([
            'user_id' => $user->id,
            'message_ids' => $messageIds,
            'participants' => [],
        ]);
        $segment->setRelation('user', $user);

        if ($this->checks->run($segment, [$operation]) === []) {
            return false;
        }

        $messages = CommsMessage::query()->whereBelongsTo($user)->whereIn('id', $messageIds)->orderBy('id')->get();
        $dossier = $this->dossier($user, $intent->topic_subject);
        $evidence = $messages->map(fn (CommsMessage $message): string => (string) $message->text)->all();

        if ($dossier !== '') {
            $evidence[] = $dossier;
        }

        $answer = $this->router->ask(
            user: $user,
            task: 'critic',
            input: $this->criticInput($intent, $quote, $reason, $messages, $dossier),
            schema: Schemas::critic(),
            eventIds: $messages->pluck('event_id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return false;
        }

        if (CriticVerdict::read($answer->output, $evidence)->refuted) {
            return false;
        }

        $event = $this->record($user, $change, [
            'action' => 'settle',
            'intent_key' => $intent->key,
            'reason' => $reason,
            'quote' => $quote,
        ], 'settle');
        $this->machine->correct($event);

        if ($intent->refresh()->state !== 'settled' || $intent->settle_reason !== $reason) {
            return false;
        }

        $this->finish($change, $event, 'applied', ['reopen_key' => $intent->key]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function settleReason(array $payload): ?string
    {
        $reason = (string) ($payload['reason'] ?? $payload['settle_reason'] ?? '');

        return match ($reason) {
            'answered_elsewhere', 'taken_by_other' => 'taken_by_other',
            'withdrawn', 'drop' => 'drop',
            'delivery' => 'delivery',
            'decision', '' => 'decision',
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    private function dayMessageIds(User $user, CarbonImmutable $day, CommsIntent $intent): array
    {
        $topic = $intent->topic_subject;
        $intentIds = $topic === null || $topic === ''
            ? collect([$intent->id])
            : CommsIntent::query()->whereBelongsTo($user)->where('topic_subject', $topic)->pluck('id');

        return CommsConversationLink::query()
            ->whereBelongsTo($user)
            ->whereIn('intent_id', $intentIds)
            ->whereHas('message', function ($query) use ($day): void {
                $query->whereBetween('occurred_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
            })
            ->pluck('message_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     */
    private function criticInput(CommsIntent $intent, string $quote, string $reason, Collection $messages, string $dossier): string
    {
        $sources = $messages->map(fn (CommsMessage $message): string => '<source id="m'.$message->id.'">'.$message->text.'</source>')->implode("\n");

        return implode("\n", [
            'OPERATION',
            (string) json_encode([
                'op' => 'settle',
                'intent_id' => (string) $intent->id,
                'reason' => $reason,
                'quote' => $quote,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            'MESSAGES',
            $sources === '' ? '(none)' : $sources,
            '',
            'DOSSIER',
            $dossier === '' ? '(none)' : $dossier,
        ]);
    }

    private function dossier(User $user, ?string $topic): string
    {
        if ($topic === null || $topic === '') {
            return '';
        }

        return trim(str_replace(["\r", "\n"], ' ', (string) CommsTopicSearch::query()
            ->whereBelongsTo($user)
            ->where('topic_subject', $topic)
            ->orderBy('id')
            ->value('document')));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, CommsConversationLink>|null
     */
    private function links(User $user, CommsIntent $intent, array $payload): ?Collection
    {
        $ids = $this->messageIds($payload);

        if ($ids === []) {
            return null;
        }

        $links = CommsConversationLink::query()
            ->whereBelongsTo($user)
            ->where('intent_id', $intent->id)
            ->whereIn('message_id', $ids)
            ->with('message')
            ->orderBy('id')
            ->get();

        if ($links->count() !== count($ids)) {
            return null;
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function messageIds(array $payload): array
    {
        $ids = [];

        foreach ($payload['message_ids'] ?? [] as $token) {
            if (is_int($token)) {
                $ids[] = $token;
            } elseif (is_string($token) && preg_match('/^m?(\d+)$/', $token, $matches) === 1) {
                $ids[] = (int) $matches[1];
            }
        }

        return array_values(array_unique($ids));
    }

    private function intent(User $user, mixed $id): ?CommsIntent
    {
        if (! is_int($id) && ! (is_string($id) && $id !== '' && $id !== 'new')) {
            return null;
        }

        $id = (string) $id;

        return CommsIntent::query()
            ->whereBelongsTo($user)
            ->where(function ($query) use ($id): void {
                $query->where('key', $id);

                if (ctype_digit($id)) {
                    $query->orWhere('id', (int) $id);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $links
     * @return list<array{id: int, intent_id: int, uncertain: bool}>
     */
    private function priorLinks(Collection $links): array
    {
        return $links->map(fn (CommsConversationLink $link): array => [
            'id' => $link->id,
            'intent_id' => $link->intent_id,
            'uncertain' => $link->uncertain,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function record(User $user, CommsReconcileChange $change, array $normalized, string $suffix): CommsEvent
    {
        $cause = $change->decision_event_id === null ? null : CommsEvent::query()->find($change->decision_event_id);

        return $this->log->append(
            user: $user,
            type: EventType::ReconcileDecided,
            source: Source::Model,
            externalId: 'reconcile:'.$change->id.':'.$suffix,
            payload: null,
            normalized: $normalized,
            causation: $cause instanceof CommsEvent ? $cause : null,
            occurredAt: CarbonImmutable::now(),
            dispatch: false,
        );
    }

    /**
     * @param  array<string, mixed>  $prior
     */
    private function finish(CommsReconcileChange $change, CommsEvent $event, string $disposition, array $prior): void
    {
        $payload = is_array($change->payload) ? $change->payload : [];
        $payload['prior'] = $prior;
        $change->payload = $payload;
        $change->disposition = $disposition;
        $change->applied_event_id = $event->id;
        $change->save();
    }

    private function day(User $user, ?CarbonImmutable $day): CarbonImmutable
    {
        $timezone = $this->timezone($user);

        if ($day !== null) {
            return $day->timezone($timezone)->startOfDay();
        }

        return CarbonImmutable::now($timezone)->subHours(12)->startOfDay();
    }

    private function timezone(User $user): string
    {
        $timezone = CommsPreference::query()->whereBelongsTo($user)->value('timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('agenda.timezone', config('app.timezone'));
    }
}
