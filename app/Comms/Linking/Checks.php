<?php

namespace App\Comms\Linking;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Intents\IntentStateMachine;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsSegment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rejects an operation the critic must not see: a quote that is not in the
 * segment, an unknown person, a deadline before the newest message, or a
 * transition the state machine would refuse. Rejections are logged.
 */
class Checks
{
    public function __construct(
        private IntentStateMachine $machine,
        private EventLog $log,
    ) {}

    /**
     * @param  list<AgreedOperation>  $operations
     * @return list<AgreedOperation>
     */
    public function run(CommsSegment $segment, array $operations): array
    {
        $segment->loadMissing('user');
        $user = $segment->user;
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $segment->message_ids ?? [])
            ->orderBy('id')
            ->get();
        $newest = $this->newest($messages);
        $timezone = CommsPreference::query()->whereBelongsTo($user)->value('timezone')
            ?? (string) config('app.timezone');
        $texts = $messages->map(fn (CommsMessage $message): string => (string) $message->text)->all();
        $passed = [];
        $rejected = [];

        foreach ($operations as $operation) {
            $reason = $this->failure($user, $texts, $newest, $timezone, $operation->operation);

            if ($reason === null) {
                $passed[] = $operation;

                continue;
            }

            $rejected[] = [
                'op' => $operation->op(),
                'status' => 'rejected',
                'reason' => $reason,
                'quote' => (string) ($operation->operation['quote'] ?? ''),
            ];
        }

        if ($rejected !== [] && $newest instanceof CommsMessage) {
            $cause = CommsEvent::query()->find($messages->first()?->event_id);

            $this->log->append(
                user: $user,
                type: EventType::StateApplied,
                source: Source::Model,
                externalId: 'checks:'.$segment->id,
                payload: null,
                normalized: [
                    'segment_id' => $segment->id,
                    'results' => $rejected,
                ],
                causation: $cause instanceof CommsEvent ? $cause : null,
                occurredAt: $newest->occurred_at,
            );
        }

        return $passed;
    }

    /**
     * @param  list<string>  $texts
     * @param  array<string, mixed>  $operation
     */
    private function failure(User $user, array $texts, ?CommsMessage $newest, string $timezone, array $operation): ?string
    {
        $quote = (string) ($operation['quote'] ?? '');

        if (! Quote::inAny($texts, $quote)) {
            return 'quote';
        }

        if (! $this->peopleExist($user, $operation)) {
            return 'person';
        }

        if ($newest instanceof CommsMessage && ! $this->deadlineOk($operation['deadline'] ?? null, $newest->occurred_at, $timezone)) {
            return 'deadline';
        }

        if (! $newest instanceof CommsMessage) {
            return 'message';
        }

        return $this->machine->canApply($user, $newest, $operation);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function peopleExist(User $user, array $operation): bool
    {
        foreach (['requesters', 'responders', 'possible_responders'] as $field) {
            $people = $operation[$field] ?? [];

            if (! is_array($people)) {
                return false;
            }

            foreach ($people as $person) {
                if (! is_string($person) || ! $this->personExists($user, $person)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function personExists(User $user, string $person): bool
    {
        if ($person === 'unclear') {
            return true;
        }

        if (preg_match('/^person:(\d+)$/', $person, $matches) !== 1) {
            return false;
        }

        return CommsPerson::query()->whereBelongsTo($user)->whereKey((int) $matches[1])->exists();
    }

    private function deadlineOk(mixed $deadline, CarbonImmutable $newest, string $timezone): bool
    {
        $date = $this->deadlineDate($deadline);

        if ($date === null) {
            return true;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return false;
        }

        return $parsed->toDateString() >= $newest->timezone($timezone)->toDateString();
    }

    private function deadlineDate(mixed $deadline): ?string
    {
        if ($deadline === null || $deadline === '') {
            return null;
        }

        if (is_string($deadline)) {
            return $deadline;
        }

        if (! is_array($deadline)) {
            return '';
        }

        $date = $deadline['date'] ?? null;

        if ($date === null || $date === '') {
            return null;
        }

        return is_string($date) ? $date : '';
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     */
    private function newest(Collection $messages): ?CommsMessage
    {
        return $messages
            ->sortBy(fn (CommsMessage $message): array => [$message->occurred_at->getTimestamp(), $message->id])
            ->last();
    }
}
