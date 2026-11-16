<?php

namespace App\Comms\Nightly;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\MessageProjector;
use App\Comms\Events\Source;
use App\Comms\Ingestion\GmailInbound;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Ingestion\OlderMessages;
use App\Comms\Ingestion\SlackInbound;
use App\Comms\Jobs\Consuming;
use App\Comms\Linking\PipelineV2;
use App\Comms\Notify\Silenced;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsSegment;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Searches past the backfill window for a segment that named something it
 * could not see, ingests the hits as backfill, and retries that segment once.
 */
class HistoryFetch
{
    public function __construct(
        private OlderMessages $older,
        private Ingestor $ingestor,
        private MessageProjector $projector,
        private PipelineV2 $pipeline,
        private EventLog $log,
        private Silenced $silenced,
        private Consuming $consuming,
    ) {}

    public function run(User $user, ?CarbonImmutable $day = null): int
    {
        $day = $this->day($user, $day);
        $cutoff = CarbonImmutable::now()->subDays(max(1, (int) config('comms.backfill.days')));
        $wasSilenced = $this->silenced->active;
        $this->silenced->active = true;
        $fetched = 0;

        try {
            $segments = CommsSegment::query()
                ->whereBelongsTo($user)
                ->whereHas('window', function ($query) use ($day): void {
                    $query->whereBetween('last_message_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
                })
                ->orderBy('id')
                ->get();

            foreach ($segments as $segment) {
                if ($this->alreadyFetched($user, $segment) || ! $this->needsHistory($segment)) {
                    continue;
                }

                $terms = $this->terms($segment);

                if ($terms === []) {
                    continue;
                }

                $ingested = $this->ingest($user, $terms, $cutoff);
                $this->record($user, $segment, $terms, $ingested);

                if ($ingested === []) {
                    continue;
                }

                $this->pipeline->retry($segment);
                $fetched++;
            }
        } finally {
            $this->silenced->active = $wasSilenced;
        }

        return $fetched;
    }

    private function alreadyFetched(User $user, CommsSegment $segment): bool
    {
        return CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::HistoryFetched)
            ->where('external_id', 'history:'.$segment->id)
            ->exists();
    }

    private function needsHistory(CommsSegment $segment): bool
    {
        foreach ($segment->unresolved_refs ?? [] as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                return true;
            }
        }

        return $this->newTopic($segment);
    }

    /**
     * @return list<string>
     */
    private function terms(CommsSegment $segment): array
    {
        $terms = [];

        foreach ($segment->unresolved_refs ?? [] as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $terms[] = trim($ref);
            }
        }

        if ($terms === [] && $this->newTopic($segment)) {
            $restated = trim((string) $segment->restated);

            if ($restated !== '') {
                $terms[] = $restated;
            }
        }

        return array_values(array_unique($terms));
    }

    private function newTopic(CommsSegment $segment): bool
    {
        return CommsLinkDecision::query()
            ->where('segment_id', $segment->id)
            ->where('outcome', 'new_topic')
            ->exists();
    }

    /**
     * @param  list<string>  $terms
     * @return list<int>
     */
    private function ingest(User $user, array $terms, CarbonImmutable $cutoff): array
    {
        $ids = [];
        $seen = [];

        foreach ($terms as $term) {
            foreach ($this->older->search($user, $term, $cutoff) as $hit) {
                $key = $hit instanceof SlackInbound ? 'slack:'.$hit->externalId() : 'gmail:'.$hit->messageId;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $event = $this->store($user, $hit);

                if ($event instanceof CommsEvent) {
                    $this->projector->apply($event, audit: false);
                    $ids[] = $event->id;
                }
            }
        }

        return $ids;
    }

    private function store(User $user, SlackInbound|GmailInbound $hit): ?CommsEvent
    {
        $was = $this->consuming->active;
        $this->consuming->active = true;

        try {
            return $this->ingestor->ingest($user, $hit, backfill: true);
        } finally {
            $this->consuming->active = $was;
        }
    }

    /**
     * @param  list<string>  $terms
     * @param  list<int>  $ingested
     */
    private function record(User $user, CommsSegment $segment, array $terms, array $ingested): void
    {
        $this->log->append(
            user: $user,
            type: EventType::HistoryFetched,
            source: Source::User,
            externalId: 'history:'.$segment->id,
            payload: null,
            normalized: [
                'segment_id' => $segment->id,
                'terms' => $terms,
                'ingested' => $ingested,
            ],
            causation: null,
            occurredAt: CarbonImmutable::now(),
            dispatch: false,
        );
    }

    private function day(User $user, ?CarbonImmutable $day): CarbonImmutable
    {
        $timezone = CommsPreference::query()->whereBelongsTo($user)->value('timezone');
        $timezone = is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('agenda.timezone', config('app.timezone'));

        if ($day !== null) {
            return $day->timezone($timezone)->startOfDay();
        }

        return CarbonImmutable::now($timezone)->subHours(12)->startOfDay();
    }
}
