<?php

namespace App\Comms\Linking;

use App\Comms\Models\Embeddings;
use App\Models\Comms\CommsEmbedding;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Topic subjects for a restated exchange, merged by reciprocal rank fusion
 * from nicknames, full text and embedding similarity.
 */
class TopicSearch
{
    private const int FUSION_K = 60;

    public function __construct(private Embeddings $embeddings) {}

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    public function for(User $user, string $restated, array $terms): array
    {
        $phrase = $this->normalize(trim($restated.' '.implode(' ', $terms)));
        $words = $phrase === '' ? [] : array_values(array_unique(explode(' ', $phrase)));

        if ($words === []) {
            return [];
        }

        $lists = [
            $this->byAlias($user, $phrase, $words),
            $this->byText($user, $words),
            $this->byEmbedding($user, trim($restated."\n".implode("\n", $terms))),
        ];
        $scores = [];
        $bestRank = [];

        foreach ($lists as $list) {
            foreach ($list as $index => $subject) {
                $rank = $index + 1;
                $scores[$subject] = ($scores[$subject] ?? 0) + (1 / (self::FUSION_K + $rank));
                $bestRank[$subject] = min($bestRank[$subject] ?? $rank, $rank);
            }
        }

        uksort($scores, function (string $left, string $right) use ($scores, $bestRank): int {
            $score = $scores[$right] <=> $scores[$left];

            if ($score !== 0) {
                return $score;
            }

            $rank = $bestRank[$left] <=> $bestRank[$right];

            return $rank !== 0 ? $rank : strcmp($left, $right);
        });

        return array_slice(array_keys($scores), 0, (int) config('comms.candidates.topics_max', 10));
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function byAlias(User $user, string $phrase, array $words): array
    {
        $best = [];

        foreach (CommsTopicAlias::query()->whereBelongsTo($user)->orderBy('id')->get() as $alias) {
            $tier = $this->aliasTier($alias->alias_normalized, $phrase, $words);

            if ($tier === null) {
                continue;
            }

            $current = $best[$alias->topic_subject] ?? null;

            if ($current === null || [$tier['tier'], $tier['distance']] < [$current['tier'], $current['distance']]) {
                $best[$alias->topic_subject] = [...$tier, 'subject' => $alias->topic_subject];
            }
        }

        uasort($best, fn (array $left, array $right): int => [$left['tier'], $left['distance'], $left['subject']] <=> [$right['tier'], $right['distance'], $right['subject']]);

        return array_keys($best);
    }

    /**
     * @param  list<string>  $words
     * @return array{tier: int, distance: int}|null
     */
    private function aliasTier(string $alias, string $phrase, array $words): ?array
    {
        if ($alias === '') {
            return null;
        }

        if (in_array($alias, $words, true) || str_contains(' '.$phrase.' ', ' '.$alias.' ')) {
            return ['tier' => 0, 'distance' => 0];
        }

        $prefix = false;
        $distance = null;

        foreach ($words as $word) {
            foreach (array_values(array_filter(explode(' ', $alias))) as $aliasWord) {
                if ($word === $aliasWord) {
                    return ['tier' => 0, 'distance' => 0];
                }

                if (strlen($word) >= 3 && strlen($aliasWord) >= 3 && (str_starts_with($aliasWord, $word) || str_starts_with($word, $aliasWord))) {
                    $prefix = true;
                }

                if (! $this->comparable($word, $aliasWord)) {
                    continue;
                }

                $found = levenshtein($word, $aliasWord);

                if ($found > 0 && $found <= 2) {
                    $distance = $distance === null ? $found : min($distance, $found);
                }
            }
        }

        if ($prefix) {
            return ['tier' => 1, 'distance' => 0];
        }

        return $distance === null ? null : ['tier' => 2, 'distance' => $distance];
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function byText(User $user, array $words): array
    {
        $words = array_values(array_filter($words, fn (string $word): bool => strlen($word) >= 3));

        if ($words === []) {
            return [];
        }

        $scored = [];

        foreach ($this->textQuery($user, $words)->get() as $row) {
            $document = $this->normalize($row->document);
            $score = 0;

            foreach ($words as $word) {
                if (str_contains($document, $word)) {
                    $score++;
                }
            }

            if ($score > 0) {
                $scored[] = ['subject' => $row->topic_subject, 'score' => $score];
            }
        }

        usort($scored, fn (array $left, array $right): int => [$right['score'], $left['subject']] <=> [$left['score'], $right['subject']]);

        return array_column($scored, 'subject');
    }

    /**
     * @param  list<string>  $words
     * @return Builder<CommsTopicSearch>
     */
    private function textQuery(User $user, array $words): Builder
    {
        $documents = CommsTopicSearch::query()->whereBelongsTo($user);

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $documents->whereFullText(
                'document',
                implode(' ', array_map(fn (string $word): string => $word.'*', $words)),
                ['mode' => 'boolean'],
            ),
            default => $documents->where(function (Builder $any) use ($words): void {
                foreach ($words as $word) {
                    $any->orWhereRaw('lower(document) like ?', ['%'.$word.'%']);
                }
            }),
        };

        return $documents;
    }

    /**
     * @return list<string>
     */
    private function byEmbedding(User $user, string $text): array
    {
        $query = $this->embeddings->vector($text);

        if ($query === null) {
            return [];
        }

        $scored = [];

        foreach (CommsTopicSearch::query()->whereBelongsTo($user)->orderBy('id')->get() as $row) {
            $vector = $this->documentVector($row);
            $score = $vector === null ? 0.0 : Embeddings::cosine($query, $vector);

            if ($score > 0) {
                $scored[] = ['subject' => $row->topic_subject, 'score' => $score];
            }
        }

        usort($scored, fn (array $left, array $right): int => [$right['score'], $left['subject']] <=> [$left['score'], $right['subject']]);

        return array_column($scored, 'subject');
    }

    /**
     * @return array<array-key, float|int>|null
     */
    private function documentVector(CommsTopicSearch $row): ?array
    {
        if (is_string($row->embedding_hash) && $row->embedding_hash !== '') {
            $cached = CommsEmbedding::query()
                ->where('hash', $row->embedding_hash)
                ->where('model', (string) config('comms.embeddings.model'))
                ->first();

            if (is_array($cached?->vector) && $cached->vector !== []) {
                return $cached->vector;
            }
        }

        return $this->embeddings->vector($row->document);
    }

    private function normalize(string $value): string
    {
        $folded = Str::lower(Str::ascii($value));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $folded));
    }

    private function comparable(string $left, string $right): bool
    {
        $leftLength = strlen($left);
        $rightLength = strlen($right);

        return $leftLength >= 3 && $rightLength >= 3 && $leftLength <= 255 && $rightLength <= 255;
    }
}
