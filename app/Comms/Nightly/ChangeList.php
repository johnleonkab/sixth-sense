<?php

namespace App\Comms\Nightly;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsReconcileChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One morning-digest item for the night's changes. Each answer is a
 * correction. "deshacer" puts an applied link back. "no es igual" leaves a
 * proposal unapplied. "igual" accepts a proposed merge.
 */
class ChangeList
{
    public function __construct(private EventLog $log) {}

    public function publish(User $user, ?CarbonImmutable $moment = null): ?CommsNotification
    {
        $day = $this->day($user, $moment);
        $changes = $this->changes($user, $day);

        if ($changes->isEmpty()) {
            return null;
        }

        return CommsNotification::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => 'changes:'.$day->toDateString()],
            [
                'kind' => 'change_list',
                'urgency' => 'digest',
                'subject_type' => null,
                'subject_id' => null,
                'source_event_ids' => $changes
                    ->pluck('decision_event_id')
                    ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * @return array{day: string, lines: list<array{id: int, op: string, quote: ?string, why: ?string, disposition: string, answers: list<string>}>}|null
     */
    public function item(User $user, ?CarbonImmutable $moment = null): ?array
    {
        $day = $this->day($user, $moment);
        $lines = [];

        foreach ($this->changes($user, $day) as $change) {
            $lines[] = [
                'id' => $change->id,
                'op' => $change->op,
                'quote' => $change->quote,
                'why' => $change->why,
                'disposition' => $change->disposition,
                'answers' => ['igual', 'no es igual', 'deshacer'],
            ];
        }

        if ($lines === []) {
            return null;
        }

        return [
            'day' => $day->toDateString(),
            'lines' => $lines,
        ];
    }

    public function answer(User $user, CommsReconcileChange $change, string $answer): void
    {
        if ($change->user_id !== $user->id) {
            return;
        }

        $slug = match ($answer) {
            'igual' => 'igual',
            'no es igual' => 'no_es_igual',
            'deshacer' => 'deshacer',
            default => null,
        };

        if ($slug === null) {
            return;
        }

        $this->log->append(
            user: $user,
            type: EventType::UserCorrected,
            source: Source::User,
            externalId: 'change-list:'.$change->id.':'.$slug,
            payload: null,
            normalized: [
                'action' => 'change_list',
                'answer' => $slug,
                'change_id' => $change->id,
                'quote' => $change->quote,
                'op' => $change->op,
                'intent_key' => $this->intentKey($user, $change),
            ],
            causation: null,
            occurredAt: CarbonImmutable::now(),
        );

        match ($slug) {
            'igual' => app(ReconcileApplier::class)->accept($change->refresh()),
            'deshacer' => app(ReconcileApplier::class)->undo($change->refresh()),
            default => null,
        };
    }

    /**
     * @return Collection<int, CommsReconcileChange>
     */
    private function changes(User $user, CarbonImmutable $day): Collection
    {
        return CommsReconcileChange::query()
            ->whereBelongsTo($user)
            ->whereDate('run_date', $day->toDateString())
            ->orderBy('id')
            ->get();
    }

    private function intentKey(User $user, CommsReconcileChange $change): ?string
    {
        $payload = is_array($change->payload) ? $change->payload : [];
        $id = match ($change->op) {
            'merge' => $payload['b'] ?? null,
            'relink' => $payload['from_intent'] ?? null,
            'confirm_link', 'settle', 'split' => $payload['intent_id'] ?? null,
            default => null,
        };

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $key = CommsIntent::query()->whereBelongsTo($user)->whereKey((int) $id)->value('key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function day(User $user, ?CarbonImmutable $moment): CarbonImmutable
    {
        $timezone = CommsPreference::query()->whereBelongsTo($user)->value('timezone');
        $timezone = is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('agenda.timezone', config('app.timezone'));
        $moment ??= CarbonImmutable::now($timezone);

        return $moment->timezone($timezone)->subHours(12)->startOfDay();
    }
}
