<?php

namespace App\Comms\Context;

use App\Comms\Intents\PersonRef;
use App\Knowledge\KnowledgeSource;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsContextUse;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSearch;
use App\Models\KnowledgeDocument;
use App\Models\SlackPreferenceRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The only read API for what sixth-sense knows. Every reader asks for a pack
 * for its audience; every item carries a ref.
 */
class ContextEngine
{
    /** Facts about one subject in a pack, at most. */
    public const PER_SUBJECT = 6;

    /** Rules in a pack, at most. */
    public const RULES = 8;

    public function __construct(private Precedence $precedence, private PersonRef $people) {}

    /**
     * Everything this audience may read: facts (subject first, then origin,
     * then recency), open intents of the people involved, and the corpus.
     * The facts it returns are marked as used.
     */
    public function pack(User $user, ContextRequest $request): ContextPack
    {
        $audience = $request->audience;
        $about = Str::slug(trim((string) strtok(trim((string) $request->about), '<')));
        $subjects = array_map(fn (string $subject): string => Str::lower($subject), $request->subjects);
        $first = function (CommsContextFact $fact) use ($subjects, $about): bool {
            $subject = Str::lower($fact->subject);

            if (in_array($subject, $subjects, true)) {
                return true;
            }

            if ($about === '' || ! str_starts_with($subject, 'person:')) {
                return false;
            }

            // A full name is one person. A first name alone may be any of them.
            return str_contains($about, '-')
                ? $subject === 'person:'.$about
                : in_array($about, explode('-', Str::after($subject, 'person:')), true);
        };

        $facts = $this->active($user)
            ->filter(fn (CommsContextFact $fact): bool => in_array($fact->kind, $audience->kinds(), true)
                && $this->visible($fact, $audience)
                && ($audience->readsSensitive() || ! $fact->sensitive))
            ->sortBy(fn (CommsContextFact $fact): array => [
                $first($fact) ? 0 : 1,
                -$fact->origin->rank(),
                -($fact->confirmed_at?->getTimestamp() ?? 0),
            ])
            ->values();

        $kept = [];
        $perSubject = [];
        $rules = 0;
        $seen = [];
        $budget = $audience->budget();
        $spent = 0;

        foreach ($facts as $fact) {
            $text = $fact->text();
            $key = $fact->attribute.'|'.$text;

            if (($text === '' && ! isset($fact->value['title'])) || isset($seen[$key])) {
                continue;
            }

            if ($fact->attribute === 'rule' && $fact->kind !== 'channel' && ++$rules > self::RULES) {
                continue;
            }

            if ($fact->subject !== 'user' && ($perSubject[$fact->subject] = ($perSubject[$fact->subject] ?? 0) + 1) > self::PER_SUBJECT) {
                continue;
            }

            $cost = $this->tokens($text) + 6;

            if ($budget !== null && $spent + $cost > $budget) {
                continue;
            }

            $spent += $cost;
            $seen[$key] = true;
            $kept[] = $fact;
        }

        $intents = $this->intents($user, $request, $budget === null ? null : max(0, $budget - $spent));
        $documents = [];
        $findings = [];

        if (filled($request->query)) {
            $hits = array_map(fn (array $hit): CorpusDocument => $hit['document'], $this->search($user, (string) $request->query, [], 5));
            $documents = array_map(fn (CorpusDocument $document): array => [
                'ref' => $document->ref,
                'title' => $document->title,
                'text' => Str::limit($document->body, 400),
            ], $hits);
            $findings = $this->precedence->findings($hits);
        }

        $this->used($kept, $audience, $request->callRef);

        return new ContextPack(
            audience: $audience,
            facts: array_map(fn (CommsContextFact $fact): array => $this->item($fact), $kept),
            intents: $intents,
            documents: $documents,
            findings: $findings,
        );
    }

    /**
     * What the person said or the system learned, for the settings pages.
     *
     * @param  list<string>  $statuses
     * @param  list<string>|null  $kinds
     * @param  list<string>|null  $attributes
     * @return list<array{id: int, ref: string, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, origin: string, status: string, audiences: list<string>, refs: list<string>, uses: int, last_used_at: ?string, confirmed_at: ?string, contradicts: ?string}>
     */
    public function listing(User $user, array $statuses, ?array $kinds = null, ?array $attributes = null, int $limit = 200): array
    {
        $facts = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->whereIn('status', $statuses)
            ->when($kinds !== null, fn ($query) => $query->whereIn('kind', $kinds))
            ->when($attributes !== null, fn ($query) => $query->whereIn('attribute', $attributes))
            ->latest('confirmed_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        $active = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->whereIn('subject', $facts->where('status', 'proposed')->pluck('subject')->unique()->all())
            ->get();

        return $facts->map(fn (CommsContextFact $fact): array => [
            ...$this->item($fact),
            'status' => $fact->status,
            'audiences' => array_values($fact->audiences ?? []),
            'refs' => array_values($fact->refs ?? []),
            'uses' => (int) $fact->uses,
            'last_used_at' => $fact->last_used_at?->toIso8601String(),
            'confirmed_at' => $fact->confirmed_at?->toIso8601String(),
            'contradicts' => $fact->status === 'proposed'
                ? $active->first(fn (CommsContextFact $current): bool => $current->subject === $fact->subject && $current->attribute === $fact->attribute)?->text()
                : null,
        ])->values()->all();
    }

    /**
     * One fact of this person, for an edit, a rejection or an acceptance.
     *
     * @return array{kind: string, subject: string, attribute: string, value: array<string, mixed>, audiences: list<string>, status: string}|null
     */
    public function fact(User $user, int $id): ?array
    {
        $fact = CommsContextFact::query()->whereBelongsTo($user)->find($id);

        return $fact === null ? null : [
            'kind' => $fact->kind,
            'subject' => $fact->subject,
            'attribute' => $fact->attribute,
            'value' => $fact->value ?? [],
            'audiences' => array_values($fact->audiences ?? []),
            'status' => $fact->status,
        ];
    }

    /**
     * Where each value of a person came from, keyed by attribute.
     *
     * @return array<string, string>
     */
    public function origins(User $user, string $subject): array
    {
        return CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('subject', $subject)
            ->where('status', 'active')
            ->get()
            ->mapWithKeys(fn (CommsContextFact $fact): array => [$fact->attribute => $fact->origin->value])
            ->all();
    }

    /**
     * What the person explained when the system asked: terms and meetings.
     *
     * @return list<array{ref: string, subject: string, name: string, text: string, question: string, kind: string, at: CarbonImmutable}>
     */
    public function answers(User $user): array
    {
        return $this->active($user)
            ->filter(fn (CommsContextFact $fact): bool => $fact->kind === 'term' && $fact->attribute === 'meaning')
            ->sortBy(fn (CommsContextFact $fact): string => Str::lower((string) ($fact->value['name'] ?? $fact->subject)))
            ->map(fn (CommsContextFact $fact): array => [
                'ref' => (string) (collect($fact->refs ?? [])->first(fn (string $ref): bool => str_starts_with($ref, 'gap:')) ?? 'fact:'.$fact->id),
                'subject' => $fact->subject,
                'name' => (string) ($fact->value['name'] ?? Str::after($fact->subject, ':')),
                'text' => $fact->text(),
                'question' => (string) ($fact->value['question'] ?? ''),
                'kind' => str_starts_with($fact->subject, 'meeting:') ? 'meeting' : 'term',
                'at' => CarbonImmutable::instance($fact->confirmed_at ?? $fact->created_at ?? now()),
            ])
            ->values()
            ->all();
    }

    /**
     * What the person said a term or a meeting is, if they did.
     */
    public function answer(User $user, string $subject): ?string
    {
        $fact = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->where('subject', $subject)
            ->where('attribute', 'meaning')
            ->latest('id')
            ->first();

        return $fact?->text();
    }

    /**
     * How the person answers in Slack, counted from their threads, with the
     * rules they said on top. Code that only needs the numbers reads this.
     *
     * @return array<string, mixed>
     */
    public function slackSignals(User $user): array
    {
        $profile = $this->slackProfile($user);
        $signals = is_array($profile['signals'] ?? null) ? $profile['signals'] : [];
        $signals['rules'] = SlackPreferenceRule::query()
            ->whereBelongsTo($user)
            ->where('status', 'accepted')
            ->latest('id')
            ->get()
            ->map(fn (SlackPreferenceRule $rule): array => [
                'correction' => $rule->correction,
                'channel' => $rule->channel,
                'place' => $rule->place,
                'actor' => $rule->actor,
                'target' => $rule->target,
                'words' => $rule->words ?? [],
            ])
            ->all();

        return $signals;
    }

    /**
     * The last Slack attention reading: its text, its signals and when.
     *
     * @return array{text: string, signals: array<string, mixed>, at: ?CarbonImmutable}|null
     */
    public function slackProfile(User $user): ?array
    {
        $fact = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->where('subject', 'slack')
            ->where('attribute', 'profile')
            ->latest('id')
            ->first();

        return $fact === null ? null : [
            'text' => $fact->text(),
            'signals' => is_array($fact->value['signals'] ?? null) ? $fact->value['signals'] : [],
            'at' => $fact->confirmed_at !== null ? CarbonImmutable::instance($fact->confirmed_at) : null,
        ];
    }

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
     * Keyword search over the knowledge corpus, then similarity over that short list.
     *
     * @param  list<string>  $kinds
     * @return list<array{document: CorpusDocument, similarity: float}>
     */
    public function search(User $user, string $query, array $kinds = [], int $limit = 5): array
    {
        $started = hrtime(true);
        $words = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($query)) ?: [],
            fn (string $word): bool => mb_strlen($word) >= 4,
        ));
        $ranked = [];

        foreach ($this->corpus($user, $kinds, $words) as $document) {
            $haystack = mb_strtolower($document->title.' '.$document->body.' '.$document->terms);
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

        usort($ranked, fn (array $left, array $right): int => [$right['similarity'], $right['hits']] <=> [$left['similarity'], $left['hits']]);

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
     * The corpus documents of these kinds, the ones that mention a word first.
     *
     * @param  list<string>  $kinds
     * @param  list<string>  $words
     * @return list<CorpusDocument>
     */
    public function corpus(User $user, array $kinds = [], array $words = []): array
    {
        $sources = array_map(fn (string $kind): string => $kind === 'code' ? KnowledgeSource::Archive->value : $kind, $kinds);

        return KnowledgeDocument::query()
            ->whereBelongsTo($user)
            ->when($sources !== [], fn ($query) => $query->whereIn('source', $sources))
            ->when($words !== [], fn ($query) => $query->where(function ($matching) use ($words): void {
                foreach (array_slice($words, 0, 8) as $word) {
                    $matching->orWhere('title', 'like', '%'.$word.'%')
                        ->orWhere('summary', 'like', '%'.$word.'%')
                        ->orWhere('terms', 'like', '%'.$word.'%');
                }
            }))
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (KnowledgeDocument $document): CorpusDocument => new CorpusDocument(
                ref: str_contains($document->ref, ':') ? $document->ref : $document->source->value.':'.$document->ref,
                kind: $document->source === KnowledgeSource::Archive ? 'code' : $document->source->value,
                title: (string) $document->title,
                body: trim(filled($document->summary) ? (string) $document->summary : (string) $document->terms),
                topic: $document->topic,
                updatedAt: CarbonImmutable::instance($document->updated_at ?? now()),
                terms: (string) $document->terms,
            ))
            ->all();
    }

    /**
     * @return Collection<int, CommsContextFact>
     */
    private function active(User $user): Collection
    {
        $harvesting = $user->workContext()->first()?->recent() === true;

        return CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'active')
            ->latest('confirmed_at')
            ->limit(400)
            ->get()
            ->reject(fn (CommsContextFact $fact): bool => ! $harvesting && $this->harvested($fact))
            ->values();
    }

    private function harvested(CommsContextFact $fact): bool
    {
        return $fact->origin === FactOrigin::Inferred
            && collect($fact->refs ?? [])->contains(fn (mixed $ref): bool => is_string($ref) && str_starts_with($ref, 'harvest:'));
    }

    private function visible(CommsContextFact $fact, Audience $audience): bool
    {
        $audiences = $fact->audiences ?? [];

        return $audiences === [] || $audience === Audience::Export || in_array($audience->value, $audiences, true);
    }

    /**
     * Open intents of the intent's people, or of the people asked about.
     *
     * @return list<array{ref: string, text: string}>
     */
    private function intents(User $user, ContextRequest $request, ?int $budget): array
    {
        $people = $request->intent?->participants()->pluck('person_id')->all() ?? [];

        foreach ($request->subjects as $subject) {
            if (str_starts_with($subject, 'person:')) {
                $person = $this->people->find($user, Str::after($subject, 'person:'));

                if ($person !== null) {
                    $people[] = $person->id;
                }
            }
        }

        if ($people === []) {
            return [];
        }

        $items = [];
        $spent = 0;

        $open = CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('state', '!=', 'settled')
            ->when($request->intent !== null, fn ($query) => $query->whereKeyNot($request->intent->id))
            ->whereHas('participants', fn ($query) => $query->whereIn('person_id', array_unique($people)))
            ->latest('updated_at')
            ->limit(5)
            ->get();

        foreach ($open as $intent) {
            $text = Str::limit($intent->what, 160).($intent->deadline !== null ? ' (antes del '.$intent->deadline->toDateString().')' : '');
            $cost = $this->tokens($text) + 6;

            if ($budget !== null && $spent + $cost > $budget) {
                break;
            }

            $spent += $cost;
            $items[] = ['ref' => 'intent:'.$intent->key, 'text' => $text];
        }

        return $items;
    }

    /**
     * @param  list<CommsContextFact>  $facts
     */
    private function used(array $facts, Audience $audience, ?string $callRef): void
    {
        if ($facts === []) {
            return;
        }

        $ids = array_map(fn (CommsContextFact $fact): int => $fact->id, $facts);
        CommsContextFact::query()->whereKey($ids)->incrementEach(['uses' => 1], ['last_used_at' => now()]);
        CommsContextUse::query()->insert(array_map(fn (int $id): array => [
            'fact_id' => $id,
            'audience' => $audience->value,
            'model_call_ref' => $callRef,
            'created_at' => now(),
        ], $ids));
    }

    /**
     * @return array{id: int, ref: string, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, origin: string, at: CarbonImmutable}
     */
    private function item(CommsContextFact $fact): array
    {
        return [
            'id' => $fact->id,
            'ref' => 'fact:'.$fact->id,
            'kind' => $fact->kind,
            'subject' => $fact->subject,
            'attribute' => $fact->attribute,
            'text' => $fact->text(),
            'value' => $fact->value ?? [],
            'origin' => $fact->origin->value,
            'at' => CarbonImmutable::instance($fact->confirmed_at ?? $fact->created_at ?? now()),
        ];
    }

    private function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
