<?php

namespace App\Comms\Context;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The only way a producer stores a fact: an event, then the projector.
 */
class ContextWriter
{
    public function __construct(private EventLog $log, private ContextProjector $projector) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    public function record(User $user, FactOrigin $origin, array $operations, ?CommsEvent $causation = null): CommsEvent
    {
        $event = $this->log->append(
            user: $user,
            type: match ($origin) {
                FactOrigin::Correction => EventType::ContextCorrected,
                FactOrigin::Observed => EventType::ContextObserved,
                FactOrigin::Inferred => EventType::ContextInferred,
                FactOrigin::User => EventType::UserConfigured,
            },
            source: $origin === FactOrigin::Inferred ? Source::Model : Source::User,
            externalId: 'context',
            payload: null,
            normalized: [
                'kind' => 'fact',
                'origin' => $origin->value,
                'operations' => $operations,
            ],
            causation: $causation,
            occurredAt: CarbonImmutable::now(),
            revision: (string) Str::uuid(),
            dispatch: true,
        );

        $this->projector->apply($event);

        return $event;
    }

    public function rule(User $user, string $body, ?string $layer, ?string $about, ?string $topic = null): CommsEvent
    {
        $audience = Audience::fromLayer($layer);

        return $this->record($user, FactOrigin::Correction, [[
            'op' => 'add',
            'kind' => 'rule',
            'subject' => filled($about) ? 'person:'.Str::lower($about) : 'user',
            'attribute' => 'rule',
            'value' => ['text' => $body, 'topic' => $topic],
            'audiences' => $layer === null ? [] : [$audience->value],
            'origin' => FactOrigin::Correction->value,
            'refs' => [],
        ]]);
    }
}
