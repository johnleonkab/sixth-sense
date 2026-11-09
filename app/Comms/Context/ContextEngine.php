<?php

namespace App\Comms\Context;

use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsContextUse;
use App\Models\Comms\CommsDocument;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSearch;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Support\Str;

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
            ->get()
            ->concat($this->knowledge($user, $kinds))
            ->take(30);
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

    /**
     * Everything this audience may read: stored facts, then the lessons,
     * digest and Slack profile that have not been imported yet.
     */
    public function pack(User $user, ContextRequest $request): ContextPack
    {
        $facts = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->where('sensitive', false)
            ->orderByDesc('confirmed_at')
            ->limit(80)
            ->get()
            ->filter(fn (CommsContextFact $fact): bool => $this->visible($fact, $request->audience));

        $rules = [];
        $seen = [];
        $ids = [];
        $summary = '';
        $attention = (string) ($user->slackAttentionProfile?->body ?? '');

        foreach ($facts as $fact) {
            $ids[] = $fact->id;
            $text = $fact->text();

            if ($fact->attribute === 'summary' && $summary === '') {
                $summary = $text;
            }

            if ($fact->attribute === 'rule' && $text !== '' && ! isset($seen[$text])) {
                $seen[$text] = true;
                $rules[] = ['ref' => 'fact:'.$fact->id, 'text' => $text, 'id' => $fact->id];
            }
        }

        $layer = $request->audience->lessonLayer();
        $lessons = $user->intentLessons()
            ->when($layer !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('layer')->orWhere('layer', $layer)))
            ->where(fn ($query) => $query->where('last_used_at', '>=', now()->subDays(60))
                ->orWhere(fn ($fresh) => $fresh->whereNull('last_used_at')->where('created_at', '>=', now()->subDays(60))))
            ->latest('id')
            ->limit(8)
            ->get();

        foreach ($lessons as $lesson) {
            if (isset($seen[$lesson->body])) {
                continue;
            }

            if ($request->about !== null && $lesson->about !== null && ! str_contains(Str::lower($lesson->about), Str::lower((string) strtok($request->about, ' <')))) {
                continue;
            }

            $seen[$lesson->body] = true;
            $rules[] = ['ref' => 'lesson:'.$lesson->id, 'text' => $lesson->body, 'id' => null];
        }

        if ($summary === '' && $user->workContext?->usable()) {
            $summary = (string) $user->workContext->summary;
        }

        if ($ids !== []) {
            CommsContextFact::query()->whereKey($ids)->incrementEach(['uses' => 1], ['last_used_at' => now()]);
            foreach ($ids as $id) {
                CommsContextUse::query()->create([
                    'fact_id' => $id,
                    'audience' => $request->audience->value,
                    'model_call_ref' => $request->callRef,
                ]);
            }
        }

        return new ContextPack($request->audience, $summary, $attention, array_slice($rules, 0, 8), $ids);
    }

    private function visible(CommsContextFact $fact, Audience $audience): bool
    {
        $audiences = $fact->audiences ?? [];

        return $audiences === [] || in_array($audience->value, $audiences, true);
    }

    /**
     * @param  list<string>  $kinds
     * @return list<CommsDocument>
     */
    private function knowledge(User $user, array $kinds): array
    {
        return KnowledgeDocument::query()
            ->whereBelongsTo($user)
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(function (KnowledgeDocument $document) use ($user): CommsDocument {
                $copy = new CommsDocument([
                    'user_id' => $user->id,
                    'ref' => $document->source->value.':'.$document->ref,
                    'kind' => $document->source->value,
                    'title' => $document->title,
                    'body' => trim((string) $document->summary.' '.(string) $document->terms),
                    'topic' => $document->topic,
                ]);
                $copy->updated_at = $document->updated_at;

                return $copy;
            })
            ->when($kinds !== [], fn ($documents) => $documents->filter(
                fn (CommsDocument $document): bool => in_array($document->kind, $kinds, true),
            ))
            ->values()
            ->all();
    }
}
