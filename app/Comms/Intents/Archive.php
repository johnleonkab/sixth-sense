<?php

namespace App\Comms\Intents;

use App\Comms\Context\ContextProjector;
use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\People\Relation;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Archive changes are events. Replay applies them again after people exist.
 */
class Archive
{
    public function __construct(private EventLog $log, private PersonRef $people, private ContextProjector $context) {}

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function record(User $user, array $normalized): CommsEvent
    {
        return $this->log->append(
            user: $user,
            type: EventType::UserConfigured,
            source: Source::User,
            externalId: $this->externalId($normalized),
            payload: null,
            normalized: $normalized,
            causation: null,
            occurredAt: CarbonImmutable::parse($normalized['at'] ?? now()),
            revision: (string) Str::uuid(),
        );
    }

    public function apply(CommsEvent $event): void
    {
        if ($event->user === null) {
            return;
        }

        $data = $event->normalized ?? [];
        $this->context->apply($event);

        match ($data['kind'] ?? '') {
            'person' => $this->person($event->user, $data),
            'area' => $this->area($event->user, $data),
            'preference' => $this->preference($event->user, $data),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function person(User $user, array $data): void
    {
        $person = $this->people->find($user, (string) ($data['person_ref'] ?? ''));

        if ($person === null) {
            return;
        }

        $relation = Relation::tryFrom((string) ($data['relation'] ?? '')) ?? Relation::Unknown;
        $person->relation = $relation;
        $person->importance_weight = isset($data['importance_weight']) ? (int) $data['importance_weight'] : $relation->weight();
        $person->team = $data['team'] ?? $person->team;
        $person->role = $data['role'] ?? $person->role;
        $person->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function area(User $user, array $data): void
    {
        $where = [
            'user_id' => $user->id,
            'kind' => (string) ($data['area_kind'] ?? 'product'),
            'ref' => (string) ($data['ref'] ?? ''),
        ];

        if (($data['removed'] ?? false) === true) {
            CommsOwnedArea::query()->where($where)->delete();

            return;
        }

        CommsOwnedArea::query()->updateOrCreate($where, [
            'name' => (string) ($data['name'] ?? ''),
            'keywords' => array_values($data['keywords'] ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function preference(User $user, array $data): void
    {
        CommsPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'timezone' => (string) ($data['timezone'] ?? 'Europe/Madrid'),
                'locale' => (string) ($data['locale'] ?? 'es'),
                'work_start' => (string) ($data['work_start'] ?? '09:00'),
                'work_end' => (string) ($data['work_end'] ?? '19:00'),
                'work_days' => array_values($data['work_days'] ?? [1, 2, 3, 4, 5]),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function externalId(array $normalized): string
    {
        return match ($normalized['kind'] ?? '') {
            'person' => 'person:'.($normalized['person_ref'] ?? ''),
            'area' => 'area:'.($normalized['area_kind'] ?? 'product').':'.($normalized['ref'] ?? ''),
            default => 'preference',
        };
    }
}
