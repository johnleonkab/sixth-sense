<?php

namespace App\Comms\Context;

use App\Models\Comms\CommsDocument;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSearch;
use App\Models\User;

/**
 * The only read API a playbook uses. Every item carries a ref.
 */
class ContextEngine
{
    /**
     * @return list<array{ref: string, source: string, channel: ?string, text: string, at: string, direction: string}>
     */
    public function conversation(CommsIntent $intent): array
    {
        return CommsMessage::query()
            ->whereIn('id', $intent->links()->pluck('message_id'))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (CommsMessage $message): array => [
                'ref' => 'evt:'.$message->event_id,
                'source' => $message->source->value,
                'channel' => $message->channel_ref,
                'text' => $message->text,
                'at' => $message->occurred_at->toIso8601String(),
                'direction' => (string) $message->direction,
            ])
            ->all();
    }

    public function live(User $user, string $ref): ?CommsDocument
    {
        return CommsDocument::query()->whereBelongsTo($user)->where('ref', $ref)->first();
    }

    /**
     * @return array{people: list<array{name: string, relation: string}>, areas: list<array{kind: string, ref: string, name: string, keywords: list<string>}>}
     */
    public function archive(User $user): array
    {
        return [
            'people' => CommsPerson::query()->whereBelongsTo($user)->get()->map(fn (CommsPerson $person): array => [
                'name' => $person->display_name,
                'relation' => $person->relation->value,
            ])->all(),
            'areas' => CommsOwnedArea::query()->whereBelongsTo($user)->get()->map(fn (CommsOwnedArea $area): array => [
                'kind' => $area->kind,
                'ref' => $area->ref,
                'name' => $area->name,
                'keywords' => $area->keywords ?? [],
            ])->all(),
        ];
    }

    /**
     * Keyword search over the stored corpus, then similarity over that short list.
     *
     * @param  list<string>  $kinds
     * @return list<array{document: CommsDocument, similarity: float}>
     */
    public function search(User $user, string $query, array $kinds = [], int $limit = 5): array
    {
        $started = hrtime(true);
        $words = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($query)) ?: [],
            fn (string $word): bool => mb_strlen($word) >= 4,
        ));
        $documents = CommsDocument::query()
            ->whereBelongsTo($user)
            ->when($kinds !== [], fn ($query) => $query->whereIn('kind', $kinds))
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();
        $ranked = [];

        foreach ($documents as $document) {
            $haystack = mb_strtolower($document->title.' '.$document->body);
            $hits = 0;

            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    $hits++;
                }
            }

            similar_text(mb_strtolower($query), mb_strtolower($document->title), $percent);

            if ($hits === 0 && $percent < 85) {
                continue;
            }

            $ranked[] = ['document' => $document, 'similarity' => $percent, 'hits' => $hits];
        }

        usort($ranked, function (array $left, array $right): int {
            return [$right['similarity'], $right['hits']] <=> [$left['similarity'], $left['hits']];
        });

        CommsSearch::query()->create([
            'user_id' => $user->id,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);

        return array_map(
            fn (array $row): array => ['document' => $row['document'], 'similarity' => $row['similarity']],
            array_slice($ranked, 0, $limit),
        );
    }
}
