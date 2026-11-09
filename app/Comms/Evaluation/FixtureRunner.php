<?php

namespace App\Comms\Evaluation;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Projections;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Replays a fixture and fails when the projections are not the expected ones.
 */
class FixtureRunner
{
    public function __construct(
        private EventLog $log,
        private Replay $replay,
        private Projections $projections,
    ) {}

    public function check(User $user, string $path): void
    {
        /** @var array{events: list<array<string, mixed>>, projections: array<string, list<array<string, mixed>>>} $fixture */
        $fixture = require $path;

        foreach ($fixture['events'] as $event) {
            $this->log->append(
                user: $user,
                type: EventType::from((string) $event['type']),
                source: Source::from((string) $event['source']),
                externalId: isset($event['external_id']) ? (string) $event['external_id'] : null,
                payload: isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : null,
                normalized: isset($event['normalized']) && is_array($event['normalized']) ? $event['normalized'] : null,
                causation: null,
                occurredAt: CarbonImmutable::parse((string) $event['occurred_at']),
            );
        }

        $this->replay->run($user);

        $actual = $this->projections->capture($user);

        if ($actual != $fixture['projections']) {
            throw new ProjectionMismatch($fixture['projections'], $actual);
        }
    }
}
