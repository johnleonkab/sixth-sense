<?php

namespace App\Comms\Context;

use App\Comms\Events\EventType;
use App\Comms\Intents\PersonRef;
use App\Comms\People\Relation;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Applies context events onto the fact table. The same event applied twice
 * changes nothing, so the consumer and a direct write can both run it.
 */
class ContextProjector
{
    /** @var list<string> */
    private const SINGLE = [
        'relation',
        'importance',
        'language',
        'role',
        'team',
        'tone',
        'hours',
        'summary',
        'meaning',
        'timezone',
        'locale',
        'profile',
    ];

    public function __construct(private PersonRef $people) {}

    public function apply(CommsEvent $event): void
    {
        if ($event->user === null) {
            return;
        }

        match ($event->type) {
            EventType::UserConfigured => $this->fromConfigured($event),
            EventType::ContextCorrected, EventType::ContextObserved, EventType::ContextInferred => $this->operations(
                $event,
                FactOrigin::fromEvent($event->type),
            ),
            default => null,
        };
    }

    public function expire(User $user, CarbonImmutable $at): void
    {
        CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->where('origin', FactOrigin::Inferred)
            ->where('confirmed_at', '<', $at->subDays((int) config('context.inferred_days')))
            ->update(['status' => 'expired']);

        CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->where('attribute', 'rule')
            ->where('origin', '!=', FactOrigin::User)
            ->where(function ($query) use ($at): void {
                $cutoff = $at->subDays((int) config('context.rule_days'));
                $query->where('last_used_at', '<', $cutoff)
                    ->orWhere(fn ($fresh) => $fresh->whereNull('last_used_at')->where('confirmed_at', '<', $cutoff));
            })
            ->update(['status' => 'expired']);
    }

    private function fromConfigured(CommsEvent $event): void
    {
        $data = $event->normalized ?? [];

        if (($data['operations'] ?? null) !== null) {
            $this->operations($event, FactOrigin::tryFrom((string) ($data['origin'] ?? 'user')) ?? FactOrigin::User);

            return;
        }

        match ($data['kind'] ?? '') {
            'person' => $this->person($event, $data),
            'area' => $this->area($event, $data),
            'preference' => $this->preference($event, $data),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function person(CommsEvent $event, array $data): void
    {
        $subject = 'person:'.(string) ($data['person_ref'] ?? '');
        $this->put($event, FactOrigin::User, 'person', $subject, 'relation', ['value' => (string) ($data['relation'] ?? 'unknown')]);

        if (isset($data['importance_weight'])) {
            $this->put($event, FactOrigin::User, 'person', $subject, 'importance', ['value' => (int) $data['importance_weight']]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function area(CommsEvent $event, array $data): void
    {
        $subject = 'area:'.($data['area_kind'] ?? 'product').':'.($data['ref'] ?? '');
        $operation = ($data['removed'] ?? false) === true ? 'reject' : 'add';
        $this->put($event, FactOrigin::User, 'area', $subject, 'owns', [
            'value' => (string) ($data['name'] ?? ''),
            'keywords' => array_values($data['keywords'] ?? []),
        ], $operation);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function preference(CommsEvent $event, array $data): void
    {
        $this->put($event, FactOrigin::User, 'preference', 'user', 'hours', [
            'value' => ($data['work_start'] ?? '09:00').'-'.($data['work_end'] ?? '19:00'),
            'days' => array_values($data['work_days'] ?? []),
        ]);
        $this->put($event, FactOrigin::User, 'preference', 'user', 'timezone', ['value' => (string) ($data['timezone'] ?? 'Europe/Madrid')]);
        $this->put($event, FactOrigin::User, 'preference', 'user', 'locale', ['value' => (string) ($data['locale'] ?? 'es')]);
    }

    private function operations(CommsEvent $event, FactOrigin $origin): void
    {
        foreach ($event->normalized['operations'] ?? [] as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $this->put(
                $event,
                FactOrigin::tryFrom((string) ($operation['origin'] ?? $origin->value)) ?? $origin,
                (string) ($operation['kind'] ?? 'rule'),
                (string) ($operation['subject'] ?? 'user'),
                (string) ($operation['attribute'] ?? 'rule'),
                is_array($operation['value'] ?? null) ? $operation['value'] : ['text' => (string) ($operation['value'] ?? '')],
                (string) ($operation['op'] ?? 'set'),
                is_array($operation['audiences'] ?? null) ? $operation['audiences'] : [],
                (float) ($operation['confidence'] ?? 1),
                is_array($operation['refs'] ?? null) ? $operation['refs'] : [],
                (bool) ($operation['sensitive'] ?? false),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $audiences
     * @param  list<string>  $refs
     */
    private function put(
        CommsEvent $event,
        FactOrigin $origin,
        string $kind,
        string $subject,
        string $attribute,
        array $value,
        string $operation = 'set',
        array $audiences = [],
        float $confidence = 1,
        array $refs = [],
        bool $sensitive = false,
    ): void {
        $user = $event->user;

        if (! $user instanceof User || $subject === '' || $attribute === '') {
            return;
        }

        $hash = hash('sha1', (string) json_encode($value));
        $already = CommsContextFact::query()
            ->where('event_id', $event->id)
            ->where('subject', $subject)
            ->where('attribute', $attribute)
            ->where('value_hash', $hash)
            ->exists();

        if ($already) {
            return;
        }

        if ($operation === 'reject') {
            $this->reject($user, $subject, $attribute, $hash, $event);

            return;
        }

        $active = $this->active($user, $subject, $attribute, $hash);
        $rejected = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('subject', $subject)
            ->where('attribute', $attribute)
            ->where('value_hash', $hash)
            ->where('status', 'rejected')
            ->exists();

        if ($rejected && $origin !== FactOrigin::User) {
            return;
        }

        $status = 'active';

        if ($active !== null && $active->origin->rank() > $origin->rank() && $active->value_hash !== $hash) {
            $status = 'proposed';
        } elseif ($active !== null && $active->value_hash !== $hash && in_array($attribute, self::SINGLE, true)) {
            $active->status = 'superseded';
            $active->save();
        } elseif ($active !== null && $active->value_hash === $hash) {
            $active->confirmed_at = $event->occurred_at;
            $active->confidence = max($active->confidence, $confidence);
            $active->save();
            $this->projectPerson($user, $active);

            return;
        }

        $fact = CommsContextFact::query()->create([
            'user_id' => $user->id,
            'kind' => Str::limit($kind, 24, ''),
            'subject' => $subject,
            'attribute' => $attribute,
            'value' => $value,
            'audiences' => $audiences,
            'origin' => $origin,
            'confidence' => $confidence,
            'refs' => $refs,
            'status' => $status,
            'sensitive' => $sensitive,
            'event_id' => $event->id,
            'value_hash' => $hash,
            'confirmed_at' => $event->occurred_at,
        ]);

        if ($status === 'active') {
            $this->projectPerson($user, $fact);
        }
    }

    private function reject(User $user, string $subject, string $attribute, string $hash, CommsEvent $event): void
    {
        CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('subject', $subject)
            ->where('attribute', $attribute)
            ->where('value_hash', $hash)
            ->where('status', 'active')
            ->update(['status' => 'rejected']);

        if (CommsContextFact::query()->where('event_id', $event->id)->where('value_hash', $hash)->exists()) {
            return;
        }

        CommsContextFact::query()->create([
            'user_id' => $user->id,
            'kind' => 'rule',
            'subject' => $subject,
            'attribute' => $attribute,
            'value' => ['rejected' => true],
            'audiences' => [],
            'origin' => FactOrigin::User,
            'confidence' => 1,
            'refs' => [],
            'status' => 'rejected',
            'sensitive' => false,
            'event_id' => $event->id,
            'value_hash' => $hash,
            'confirmed_at' => $event->occurred_at,
        ]);
    }

    private function active(User $user, string $subject, string $attribute, string $hash): ?CommsContextFact
    {
        $query = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('subject', $subject)
            ->where('attribute', $attribute)
            ->where('status', 'active');

        if (! in_array($attribute, self::SINGLE, true)) {
            $query->where('value_hash', $hash);
        }

        return $query->first();
    }

    private function projectPerson(User $user, CommsContextFact $fact): void
    {
        if ($fact->kind !== 'person' || ! in_array($fact->attribute, ['relation', 'importance', 'role', 'team'], true)) {
            return;
        }

        $person = $this->people->find($user, Str::after($fact->subject, 'person:'));

        if ($person === null) {
            return;
        }

        if ($fact->attribute === 'relation') {
            $relation = Relation::tryFrom((string) ($fact->value['value'] ?? '')) ?? Relation::Unknown;
            $person->relation = $relation;
            $person->importance_weight = $relation->weight();
        }

        if ($fact->attribute === 'importance') {
            $person->importance_weight = (int) ($fact->value['value'] ?? $person->importance_weight);
        }

        if ($fact->attribute === 'role') {
            $person->role = (string) ($fact->value['value'] ?? $person->role);
        }

        if ($fact->attribute === 'team') {
            $person->team = (string) ($fact->value['value'] ?? $person->team);
        }

        $person->save();
    }
}
