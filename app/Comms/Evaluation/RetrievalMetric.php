<?php

namespace App\Comms\Evaluation;

use App\Comms\Linking\CandidateRetriever;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsSegment;
use App\Models\User;

/**
 * Share of golden segments whose expected topic and intent are in the top ten.
 */
class RetrievalMetric
{
    public function __construct(private CandidateRetriever $retriever) {}

    /**
     * @return array{topic: array{hits: int, total: int}, intent: array{hits: int, total: int}}
     */
    public function measure(?User $user = null): array
    {
        $counts = [
            'topic' => ['hits' => 0, 'total' => 0],
            'intent' => ['hits' => 0, 'total' => 0],
        ];
        $labels = CommsGoldenLabel::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->whereNotNull('segment_id')
            ->whereNotNull('expected')
            ->orderBy('id')
            ->get();
        $segments = CommsSegment::query()
            ->whereIn('id', $labels->pluck('segment_id'))
            ->get()
            ->keyBy('id');

        foreach ($labels as $label) {
            $segment = $segments->get($label->segment_id);

            if (! $segment instanceof CommsSegment || $segment->user_id !== $label->user_id) {
                continue;
            }

            $expected = is_array($label->expected) ? $label->expected : [];
            $topic = $expected['topic'] ?? null;
            $topic = is_string($topic) && $topic !== '' ? $topic : null;
            $intent = $this->intentId($expected['intent'] ?? null);

            if ($topic === null && $intent === null) {
                continue;
            }

            $candidates = $this->retriever->for($segment);
            $topics = array_slice($candidates->topics, 0, (int) config('comms.candidates.topics_max', 10));
            $intents = array_slice($candidates->intents, 0, (int) config('comms.candidates.intents_max', 10));

            if ($topic !== null) {
                $counts['topic']['total']++;

                if (in_array($topic, $topics, true)) {
                    $counts['topic']['hits']++;
                }
            }

            if ($intent !== null) {
                $counts['intent']['total']++;

                if (in_array($intent, $intents, true)) {
                    $counts['intent']['hits']++;
                }
            }
        }

        return $counts;
    }

    private function intentId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
