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
    public function __construct(private EventLog $log, private ContextProjector $projector, private ContextEngine $context) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @param  ?string  $revision  The same revision twice is one event, for producers that may run again.
     */
    public function record(User $user, FactOrigin $origin, array $operations, ?CommsEvent $causation = null, ?string $revision = null): CommsEvent
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
            occurredAt: $causation?->occurred_at !== null ? CarbonImmutable::parse($causation->occurred_at) : CarbonImmutable::now(),
            revision: $revision ?? (string) Str::uuid(),
            dispatch: true,
        );

        $this->projector->apply($event);

        return $event;
    }

    /**
     * A rule taught by a correction, for one layer (or all of them when
     * null), about someone when it names them. A newer rule with the same
     * topic replaces the older one.
     *
     * @param  list<string>  $refs
     */
    public function rule(User $user, string $body, ?string $layer, ?string $about = null, ?string $topic = null, array $refs = [], ?CommsEvent $causation = null, ?string $revision = null): CommsEvent
    {
        return $this->record($user, FactOrigin::Correction, [[
            'op' => 'add',
            'kind' => 'rule',
            'subject' => $this->subject($about),
            'attribute' => 'rule',
            'value' => array_filter([
                'text' => Str::limit(trim($body), 240),
                'topic' => $topic,
                'name' => filled($about) ? Str::limit(trim((string) $about), 120, '') : null,
            ], fn (mixed $value): bool => $value !== null),
            'audiences' => $layer === null ? [] : [Audience::fromLayer($layer)->value],
            'refs' => $refs,
        ]], $causation, $revision);
    }

    /**
     * The person deleted it: it is not brought back by a producer.
     */
    public function reject(User $user, int $factId): bool
    {
        $fact = $this->context->fact($user, $factId);

        if ($fact === null) {
            return false;
        }

        $this->record($user, FactOrigin::User, [[
            'op' => 'reject',
            'kind' => $fact['kind'],
            'subject' => $fact['subject'],
            'attribute' => $fact['attribute'],
            'value' => $fact['value'],
        ]]);

        return true;
    }

    /**
     * The person confirmed a proposed value: it becomes theirs.
     */
    public function accept(User $user, int $factId): bool
    {
        $fact = $this->context->fact($user, $factId);

        if ($fact === null) {
            return false;
        }

        $this->record($user, FactOrigin::User, [[
            'op' => 'set',
            'kind' => $fact['kind'],
            'subject' => $fact['subject'],
            'attribute' => $fact['attribute'],
            'value' => $fact['value'],
            'audiences' => $fact['audiences'],
        ]]);

        return true;
    }

    /**
     * The person rewrote it: the old wording is rejected, theirs is kept.
     */
    public function edit(User $user, int $factId, string $text): bool
    {
        $fact = $this->context->fact($user, $factId);

        if ($fact === null) {
            return false;
        }

        $value = $fact['value'];

        if (array_key_exists('text', $value) || ! array_key_exists('value', $value)) {
            $value['text'] = trim($text);
        } else {
            $value['value'] = trim($text);
        }

        $this->record($user, FactOrigin::User, [
            [
                'op' => 'reject',
                'kind' => $fact['kind'],
                'subject' => $fact['subject'],
                'attribute' => $fact['attribute'],
                'value' => $fact['value'],
            ],
            [
                'op' => $fact['attribute'] === 'rule' ? 'add' : 'set',
                'kind' => $fact['kind'],
                'subject' => $fact['subject'],
                'attribute' => $fact['attribute'],
                'value' => $value,
                'audiences' => $fact['audiences'],
            ],
        ]);

        return true;
    }

    public function subject(?string $about): string
    {
        return filled($about) ? 'person:'.Str::lower(Str::limit(trim((string) $about), 120, '')) : 'user';
    }
}
