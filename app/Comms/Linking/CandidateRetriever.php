<?php

namespace App\Comms\Linking;

use App\Comms\Intents\CandidateRetriever as IntentRetriever;
use App\Comms\Models\Embeddings;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsSegment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Hard links, then topics, then intents. The v1 retriever still scores
 * intents; cosine adds the ones whose ask matches the exchange.
 */
class CandidateRetriever
{
    private const float COSINE = 0.5;

    public function __construct(
        private HardLinks $hardLinks,
        private TopicSearch $topics,
        private IntentRetriever $intents,
        private Embeddings $embeddings,
    ) {}

    public function for(CommsSegment $segment): Candidates
    {
        $user = $segment->user;
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $segment->message_ids ?? [])
            ->orderBy('id')
            ->get();
        $terms = array_values(array_filter(
            $segment->unresolved_refs ?? [],
            fn (mixed $term): bool => is_string($term) && $term !== '',
        ));
        $topics = $this->topics->for($user, (string) $segment->restated, $terms);
        $hard = array_values(array_filter(
            $this->hardLinks->for($segment),
            fn (string $key): bool => $this->intentId($key) !== null,
        ));
        $ids = array_map(fn (string $key): int => (int) $this->intentId($key), $hard);

        foreach ($this->openOnTopics($user, array_slice($topics, 0, 3)) as $id) {
            $ids[] = $id;
        }

        foreach ($messages as $message) {
            foreach ($this->intents->for($user, $message) as $intent) {
                $ids[] = $intent->id;
            }
        }

        foreach ($this->byCosine($user, (string) $segment->restated, $messages) as $id) {
            $ids[] = $id;
        }

        $ids = array_slice(array_values(array_unique($ids)), 0, (int) config('comms.candidates.intents_max', 10));
        $ranking = [];

        foreach ($hard as $key) {
            if (in_array((int) $this->intentId($key), $ids, true)) {
                $ranking[] = $key;
            }
        }

        foreach ($topics as $subject) {
            $ranking[] = 'topic:'.$subject;
        }

        foreach ($ids as $id) {
            $key = 'intent:'.$id;

            if (! in_array($key, $ranking, true)) {
                $ranking[] = $key;
            }
        }

        return new Candidates($topics, $ids, $ranking);
    }

    /**
     * @param  list<string>  $subjects
     * @return list<int>
     */
    private function openOnTopics(User $user, array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        return CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('state', 'open')
            ->whereIn('topic_subject', $subjects)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     * @return list<int>
     */
    private function byCosine(User $user, string $restated, Collection $messages): array
    {
        $vector = $this->embeddings->vector($restated);

        if ($vector === null) {
            return [];
        }

        $at = $messages->max('occurred_at') ?? CarbonImmutable::now();
        $since = CarbonImmutable::parse($at)->subDays((int) config('comms.candidates.settled_window_days', 14));
        $ids = [];

        $intents = CommsIntent::query()
            ->whereBelongsTo($user)
            ->where(function ($query) use ($since): void {
                $query->where('state', 'open')
                    ->orWhere(function ($settled) use ($since): void {
                        $settled->where('state', 'settled')->where('settled_at', '>=', $since);
                    });
            })
            ->orderBy('id')
            ->get(['id', 'what']);

        foreach ($intents as $intent) {
            $what = $this->embeddings->vector((string) $intent->what);

            if ($what !== null && Embeddings::cosine($vector, $what) >= self::COSINE) {
                $ids[] = $intent->id;
            }
        }

        return $ids;
    }

    private function intentId(string $key): ?int
    {
        if (! str_starts_with($key, 'intent:')) {
            return null;
        }

        $id = substr($key, 7);

        return ctype_digit($id) ? (int) $id : null;
    }
}
