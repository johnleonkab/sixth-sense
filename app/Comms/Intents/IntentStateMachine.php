<?php

namespace App\Comms\Intents;

use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSignal;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The only code that turns matcher operations into intents. A quote that is
 * not in the message, or a settle the rules forbid, is recorded as rejected.
 */
class IntentStateMachine
{
    public function __construct(
        private PersonRef $people,
        private ImportanceCalculator $importance,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function decide(
        User $user,
        CommsMessage $message,
        array $operations,
        float $confidence,
        ?string $bestIntentKey,
        TriageResult $triage,
    ): array {
        $floor = (float) config('comms.confidence_floor');

        if ($confidence < $floor && $bestIntentKey !== null) {
            $operations = [[
                'op' => 'attach',
                'intent_id' => $bestIntentKey,
                'quote' => $message->text,
                'confidence' => $confidence,
                'uncertain' => true,
            ]];
        }

        $results = [];
        $openIndex = 0;

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $judged = $this->judge($user, $message, $operation, $openIndex, $triage);
            $results[] = $judged;

            if ($judged['status'] === 'accepted' && $judged['op'] === 'open') {
                $openIndex++;
            }

            if ($judged['status'] === 'rejected' && $judged['op'] === 'reopen' && $judged['reason'] === 'window' && filled($operation['what'] ?? null)) {
                $opened = $this->judge($user, $message, [
                    'op' => 'open',
                    'what' => $operation['what'],
                    'requesters' => $operation['requesters'] ?? [],
                    'responders' => $operation['responders'] ?? [],
                    'possible_responders' => $operation['possible_responders'] ?? [],
                    'quote' => $operation['quote'] ?? '',
                    'confidence' => $operation['confidence'] ?? $confidence,
                    'expects' => $operation['expects'] ?? null,
                    'deadline' => $operation['deadline'] ?? null,
                    'deadline_text' => $operation['deadline_text'] ?? null,
                    'flags' => $operation['flags'] ?? [],
                ], $openIndex, $triage);
                $results[] = $opened;

                if ($opened['status'] === 'accepted') {
                    $openIndex++;
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function effect(User $user, CommsMessage $message, array $result, ?CommsEvent $stateEvent, bool $recompute): array
    {
        $intent = match ($result['op']) {
            'open' => $this->open($user, $message, $result, $stateEvent),
            'attach', 'settle', 'reopen', 'update', 'acknowledge' => $this->touch($user, $message, $result, $stateEvent),
            'signal' => $this->signal($user, $message, $result),
            default => null,
        };

        if ($intent instanceof CommsIntent) {
            $intent->last_activity_at = $message->occurred_at;
            $intent->importance = $recompute ? $this->importance->score($intent) : (int) ($result['importance'] ?? $intent->importance);
            $intent->save();
            $result['importance'] = $intent->importance;
            $result['intent_key'] = $intent->key;
        }

        return $result;
    }

    public function replay(CommsEvent $event): void
    {
        if ($event->user === null) {
            return;
        }

        $message = CommsMessage::query()
            ->whereBelongsTo($event->user)
            ->where('source', $event->source)
            ->where('external_id', $event->external_id)
            ->first();

        if ($message === null) {
            return;
        }

        foreach ($event->normalized['results'] ?? [] as $result) {
            if (is_array($result) && ($result['status'] ?? '') === 'accepted') {
                $this->effect($event->user, $message, $result, $event, recompute: false);
            }
        }
    }

    public function correct(CommsEvent $event): void
    {
        if ($event->user === null) {
            return;
        }

        $data = $event->normalized ?? [];
        $intent = CommsIntent::query()->whereBelongsTo($event->user)->where('key', (string) ($data['intent_key'] ?? ''))->first();

        if ($intent === null) {
            return;
        }

        match ($data['action'] ?? '') {
            'close' => $this->close($intent, $event, 'drop'),
            'importance' => $this->overrideImportance($intent, $event, (int) ($data['importance'] ?? 0)),
            'still_open' => $this->reopen($intent, $event),
            'rematch' => $this->rematch($event->user, $intent, $event, $data),
            'merge' => $this->merge($event->user, $intent, $event, $data),
            'split' => $this->split($event->user, $intent, $event, $data),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function judge(User $user, CommsMessage $message, array $operation, int $openIndex, TriageResult $triage): array
    {
        $op = (string) ($operation['op'] ?? '');
        $quote = (string) ($operation['quote'] ?? '');
        $confidence = (float) ($operation['confidence'] ?? 0);
        $result = [
            'op' => $op,
            'status' => 'rejected',
            'reason' => 'quote',
            'confidence' => $confidence,
            'quote' => $quote,
            'uncertain' => (bool) ($operation['uncertain'] ?? false) || $confidence < (float) config('comms.confidence_floor'),
            'intent_key' => isset($operation['intent_id']) ? (string) $operation['intent_id'] : null,
        ];

        if (! $this->quoteInMessage($message->text, $quote)) {
            return $result;
        }

        [$requesters, $requestersMissing] = $this->people->resolveAll($user, array_values($operation['requesters'] ?? []));
        [$responders, $respondersMissing] = $this->people->resolveAll($user, array_values($operation['responders'] ?? []));
        [$possible, $possibleMissing] = $this->people->resolveAll($user, array_values($operation['possible_responders'] ?? []));

        if ($requestersMissing || $respondersMissing || $possibleMissing) {
            return [...$result, 'reason' => 'person'];
        }

        $result['requesters'] = array_map(fn (CommsPerson $person): string => $this->people->key($person), $requesters);
        $result['responders'] = array_map(fn (CommsPerson $person): string => $this->people->key($person), $responders);
        $result['possible_responders'] = array_map(fn (CommsPerson $person): string => $this->people->key($person), $possible);
        $result['what'] = isset($operation['what']) ? mb_substr((string) $operation['what'], 0, 280) : null;
        $result['expects'] = isset($operation['expects']) ? (string) $operation['expects'] : null;
        $result['deadline'] = isset($operation['deadline']) ? (string) $operation['deadline'] : null;
        $result['deadline_text'] = isset($operation['deadline_text']) ? (string) $operation['deadline_text'] : null;
        $result['settle_reason'] = $this->settleReason($operation);
        $result['summary'] = isset($operation['summary']) ? mb_substr((string) $operation['summary'], 0, 280) : null;
        $result['flags'] = $this->flags($operation, $triage, $result['uncertain']);

        return match ($op) {
            'open' => $this->judgeOpen($user, $message, $result, $requesters, $responders, $openIndex),
            'attach' => $this->judgeExisting($user, $result, null),
            'settle' => $this->judgeSettle($user, $message, $result),
            'reopen' => $this->judgeReopen($user, $message, $result),
            'update' => $this->judgeExisting($user, $result, 'open'),
            'acknowledge' => $this->judgeExisting($user, $result, 'open'),
            'signal' => [...$result, 'status' => 'accepted', 'reason' => null],
            'noise' => [...$result, 'status' => 'accepted', 'reason' => null],
            default => [...$result, 'reason' => 'unknown'],
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<CommsPerson>  $requesters
     * @param  list<CommsPerson>  $responders
     * @return array<string, mixed>
     */
    private function judgeOpen(User $user, CommsMessage $message, array $result, array $requesters, array $responders, int $openIndex): array
    {
        if (! filled($result['what'] ?? null)) {
            return [...$result, 'reason' => 'what'];
        }

        $duplicate = $this->duplicate($user, (string) $result['what'], $requesters, $responders);

        if ($duplicate !== null) {
            return [
                ...$result,
                'op' => 'attach',
                'status' => 'accepted',
                'reason' => null,
                'intent_key' => $duplicate->key,
                'uncertain' => false,
            ];
        }

        return [
            ...$result,
            'status' => 'accepted',
            'reason' => null,
            'intent_key' => 'evt:'.$message->event_id.':'.$openIndex,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function judgeExisting(User $user, array $result, ?string $state): array
    {
        $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', (string) $result['intent_key'])->first();

        if ($intent === null) {
            return [...$result, 'reason' => 'intent'];
        }

        if ($state !== null && $intent->state !== $state) {
            return [...$result, 'reason' => 'state'];
        }

        return [...$result, 'status' => 'accepted', 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function judgeSettle(User $user, CommsMessage $message, array $result): array
    {
        if ($this->isAcknowledgement($message->text)) {
            return [...$result, 'reason' => 'acknowledgement'];
        }

        $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', (string) $result['intent_key'])->first();

        if ($intent === null) {
            return [...$result, 'reason' => 'intent'];
        }

        if ($intent->state !== 'open') {
            return [...$result, 'reason' => 'state'];
        }

        $reason = (string) ($result['settle_reason'] ?? '');

        if ($reason === '') {
            $reason = 'decision';
            $result['settle_reason'] = $reason;
        }

        if (! in_array($reason, ['delivery', 'decision', 'drop', 'taken_by_other'], true)) {
            return [...$result, 'reason' => 'reason'];
        }

        $authorIsResponder = $intent->participants()->where('person_id', $message->author_person_id)->where('role', 'responder')->exists();
        $allowListed = ($message->flags['label'] ?? null) === 'delivery';

        if ($reason === 'delivery' && ! $authorIsResponder && ! $allowListed) {
            return [...$result, 'reason' => 'delivery'];
        }

        return [...$result, 'status' => 'accepted', 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function judgeReopen(User $user, CommsMessage $message, array $result): array
    {
        $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', (string) $result['intent_key'])->first();

        if ($intent === null || $intent->state !== 'settled' || $intent->settled_at === null) {
            return [...$result, 'reason' => 'state'];
        }

        $window = (int) config('comms.candidates.settled_window_days', 14);

        if ($intent->settled_at->lt($message->occurred_at->copy()->subDays($window))) {
            return [...$result, 'reason' => 'window'];
        }

        return [...$result, 'status' => 'accepted', 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, bool|string>
     */
    private function flags(array $operation, TriageResult $triage, bool $uncertain): array
    {
        $named = array_values($operation['flags'] ?? []);
        $flags = [
            'sensitive' => $triage->sensitive,
            'urgent' => $triage->urgent,
            'production' => in_array('production', $named, true),
            'client' => in_array('client', $named, true),
            'money' => in_array('money', $named, true),
            'uncertain' => $uncertain,
        ];

        if (in_array($operation['kind'] ?? '', ['question', 'review_comment', 'choice', 'prepare'], true)) {
            $flags['kind'] = $operation['kind'];
        }

        return $flags;
    }

    /**
     * @param  list<CommsPerson>  $requesters
     * @param  list<CommsPerson>  $responders
     */
    private function duplicate(User $user, string $what, array $requesters, array $responders): ?CommsIntent
    {
        $threshold = (float) config('comms.candidates.duplicate_similarity', 92);
        $wantedRequesters = collect($requesters)->pluck('id')->sort()->values()->all();
        $wantedResponders = collect($responders)->pluck('id')->sort()->values()->all();

        return CommsIntent::query()->whereBelongsTo($user)->where('state', 'open')->with('participants')->get()
            ->first(function (CommsIntent $intent) use ($what, $threshold, $wantedRequesters, $wantedResponders): bool {
                $requesters = $intent->participants->where('role', 'requester')->pluck('person_id')->sort()->values()->all();
                $responders = $intent->participants->where('role', 'responder')->pluck('person_id')->sort()->values()->all();

                if ($requesters !== $wantedRequesters || $responders !== $wantedResponders) {
                    return false;
                }

                similar_text(mb_strtolower($intent->what), mb_strtolower($what), $percent);

                return $percent >= $threshold;
            });
    }

    private function isAcknowledgement(string $text): bool
    {
        $phrases = config('comms.acknowledgements', []);
        $normalized = mb_strtolower(trim($text));

        return is_array($phrases) && in_array($normalized, array_map(fn (string $phrase): string => mb_strtolower($phrase), $phrases), true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function open(User $user, CommsMessage $message, array $result, ?CommsEvent $stateEvent): CommsIntent
    {
        $intent = CommsIntent::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => (string) $result['intent_key']],
            [
                'what' => (string) $result['what'],
                'state' => 'open',
                'settle_reason' => null,
                'deadline' => $result['deadline'] ?? null,
                'deadline_text' => $result['deadline_text'] ?? null,
                'expects' => $result['expects'] ?? null,
                'flags' => $result['flags'] ?? [],
                'opened_event_id' => $message->event_id,
                'last_change_summary' => mb_substr((string) ($result['quote'] ?? $result['what']), 0, 280),
                'last_change_event_id' => $stateEvent?->id,
                'settled_at' => null,
                'settled_event_id' => null,
            ],
        );

        $this->syncRole($user, $intent, 'requester', $result['requesters'] ?? []);
        $this->syncRole($user, $intent, 'responder', $result['responders'] ?? []);
        $this->syncRole($user, $intent, 'possible_responder', $result['possible_responders'] ?? []);
        $this->link($user, $intent, $message, $result, $stateEvent);

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function touch(User $user, CommsMessage $message, array $result, ?CommsEvent $stateEvent): ?CommsIntent
    {
        $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', (string) ($result['intent_key'] ?? ''))->first();

        if ($intent === null) {
            return null;
        }

        if ($result['op'] === 'settle') {
            $intent->state = 'settled';
            $intent->settle_reason = $result['settle_reason'] ?? null;
            $intent->settled_at = $message->occurred_at;
            $intent->settled_event_id = $stateEvent?->id ?? $message->event_id;
        }

        if ($result['op'] === 'reopen') {
            $intent->state = 'open';
            $intent->settle_reason = null;
            $intent->settled_at = null;
            $intent->settled_event_id = null;
        }

        if ($result['op'] === 'update') {
            if (array_key_exists('deadline', $result) && $result['deadline'] !== null) {
                $intent->deadline = $result['deadline'];
                $intent->deadline_text = $result['deadline_text'] ?? $intent->deadline_text;
            }

            if (($result['responders'] ?? []) !== []) {
                $this->syncRole($user, $intent, 'responder', $result['responders']);
            }

            if (is_array($result['flags'] ?? null)) {
                $intent->flags = array_merge($intent->flags ?? [], $result['flags']);
            }
        }

        if ($result['op'] === 'acknowledge') {
            $intent->acknowledged_at = $message->occurred_at;
        }

        $intent->last_change_summary = mb_substr((string) ($result['quote'] ?? $intent->what), 0, 280);
        $intent->last_change_event_id = $stateEvent?->id;
        $intent->save();
        $this->link($user, $intent, $message, $result, $stateEvent);

        return $intent->refresh();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function signal(User $user, CommsMessage $message, array $result): ?CommsIntent
    {
        $intent = filled($result['intent_key'] ?? null)
            ? CommsIntent::query()->whereBelongsTo($user)->where('key', (string) $result['intent_key'])->first()
            : null;

        CommsSignal::query()->create([
            'user_id' => $user->id,
            'message_id' => $message->id,
            'intent_id' => $intent?->id,
            'summary' => (string) ($result['summary'] ?? $result['quote'] ?? ''),
            'importance' => (int) ($result['importance'] ?? 0),
        ]);

        return $intent;
    }

    /**
     * @param  list<string>  $refs
     */
    private function syncRole(User $user, CommsIntent $intent, string $role, array $refs): void
    {
        $ids = [];

        foreach ($refs as $ref) {
            $person = $this->people->find($user, $ref);

            if ($person === null) {
                continue;
            }

            $ids[] = $person->id;
            CommsIntentParticipant::query()->updateOrCreate(
                ['intent_id' => $intent->id, 'person_id' => $person->id, 'role' => $role],
                ['user_id' => $user->id],
            );
        }

        CommsIntentParticipant::query()
            ->where('intent_id', $intent->id)
            ->where('role', $role)
            ->when($ids !== [], fn ($query) => $query->whereNotIn('person_id', $ids))
            ->when($ids === [], fn ($query) => $query)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function link(User $user, CommsIntent $intent, CommsMessage $message, array $result, ?CommsEvent $stateEvent): void
    {
        CommsConversationLink::query()->updateOrCreate(
            ['intent_id' => $intent->id, 'message_id' => $message->id],
            [
                'user_id' => $user->id,
                'operation' => $result['op'],
                'confidence' => (float) ($result['confidence'] ?? 0),
                'uncertain' => (bool) ($result['uncertain'] ?? false),
                'state_event_id' => $stateEvent?->id,
            ],
        );
    }

    private function close(CommsIntent $intent, CommsEvent $event, string $reason): void
    {
        $intent->state = 'settled';
        $intent->settle_reason = $reason;
        $intent->settled_at = $event->occurred_at;
        $intent->settled_event_id = $event->id;
        $intent->last_change_event_id = $event->id;
        $intent->last_change_summary = $reason === 'merged' ? 'Unida con otra' : 'Cerrada';
        $intent->save();
    }

    private function overrideImportance(CommsIntent $intent, CommsEvent $event, int $importance): void
    {
        $intent->importance_override = max(0, min(100, $importance));
        $intent->importance_override_until = CarbonImmutable::parse($event->occurred_at)->addDays(30);
        $intent->importance = $intent->importance_override;
        $intent->last_change_event_id = $event->id;
        $intent->save();
    }

    private function reopen(CommsIntent $intent, CommsEvent $event): void
    {
        $intent->state = 'open';
        $intent->settle_reason = null;
        $intent->settled_at = null;
        $intent->settled_event_id = null;
        $intent->last_change_event_id = $event->id;
        $intent->last_change_summary = 'Sigue abierto';
        $intent->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rematch(User $user, CommsIntent $intent, CommsEvent $event, array $data): void
    {
        $target = $this->intent($user, (string) ($data['target_key'] ?? ''));
        $message = $this->message($user, $data['message_source'] ?? null, $data['message_external_id'] ?? null);

        if ($target === null || $message === null || $target->id === $intent->id) {
            return;
        }

        $this->give($user, $intent, $target, $message, $event);
        $intent->last_change_event_id = $event->id;
        $intent->last_change_summary = 'Mensaje movido';
        $intent->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merge(User $user, CommsIntent $intent, CommsEvent $event, array $data): void
    {
        $target = $this->intent($user, (string) ($data['target_key'] ?? ''));

        if ($target === null || $target->id === $intent->id) {
            return;
        }

        $intent->load('links.message');

        foreach ($intent->links as $link) {
            if ($link->message !== null) {
                $this->give($user, $intent, $target, $link->message, $event);
            }
        }

        $this->close($intent, $event, 'merged');
        $intent->last_change_summary = 'Unida con '.$target->what;
        $intent->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function split(User $user, CommsIntent $intent, CommsEvent $event, array $data): void
    {
        $created = CommsIntent::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => 'evt:'.$event->id.':0'],
            [
                'what' => mb_substr((string) ($data['what'] ?? $intent->what), 0, 280),
                'state' => 'open',
                'flags' => $intent->flags ?? [],
                'importance' => $intent->importance,
                'opened_event_id' => $event->id,
                'last_change_event_id' => $event->id,
                'last_change_summary' => 'Separada',
                'last_activity_at' => $event->occurred_at,
            ],
        );

        $intent->loadMissing('participants');

        foreach ($intent->participants as $participant) {
            CommsIntentParticipant::query()->updateOrCreate(
                ['intent_id' => $created->id, 'person_id' => $participant->person_id, 'role' => $participant->role],
                ['user_id' => $user->id],
            );
        }

        foreach ($data['messages'] ?? [] as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $message = $this->message($user, $ref['source'] ?? null, $ref['external_id'] ?? null);

            if ($message !== null) {
                $this->give($user, $intent, $created, $message, $event);
            }
        }
    }

    private function intent(User $user, string $key): ?CommsIntent
    {
        if ($key === '') {
            return null;
        }

        return CommsIntent::query()->whereBelongsTo($user)->where('key', $key)->first();
    }

    private function message(User $user, mixed $source, mixed $externalId): ?CommsMessage
    {
        if (! is_string($source) || ! is_string($externalId) || $externalId === '') {
            return null;
        }

        return CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first();
    }

    private function give(User $user, CommsIntent $from, CommsIntent $to, CommsMessage $message, CommsEvent $event): void
    {
        $current = CommsConversationLink::query()
            ->where('intent_id', $from->id)
            ->where('message_id', $message->id)
            ->first();

        $occupied = CommsConversationLink::query()
            ->where('intent_id', $to->id)
            ->where('message_id', $message->id)
            ->exists();

        if ($occupied) {
            $current?->delete();

            return;
        }

        if ($current === null) {
            CommsConversationLink::query()->create([
                'user_id' => $user->id,
                'intent_id' => $to->id,
                'message_id' => $message->id,
                'operation' => 'attach',
                'confidence' => 1,
                'uncertain' => false,
                'state_event_id' => $event->id,
            ]);

            return;
        }

        $current->intent_id = $to->id;
        $current->uncertain = false;
        $current->state_event_id = $event->id;
        $current->save();
    }

    private function quoteInMessage(string $message, string $quote): bool
    {
        if ($quote === '') {
            return false;
        }

        if (str_contains($message, $quote)) {
            return true;
        }

        $haystack = $this->normalizedForQuote($message);
        $needle = $this->normalizedForQuote($quote);

        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function normalizedForQuote(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/^[ \t]*>+[ \t]?/mu', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function settleReason(array $operation): ?string
    {
        foreach (['settle_reason', 'reason'] as $key) {
            $value = (string) ($operation[$key] ?? '');

            if (in_array($value, ['delivery', 'decision', 'drop', 'taken_by_other'], true)) {
                return $value;
            }
        }

        return null;
    }
}
