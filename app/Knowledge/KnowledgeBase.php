<?php

namespace App\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Searches the index of the archive, Jira, Notion, meetings and the person's
 * answers. Every agent uses this same search: the voice through a tool, and
 * the one-shot agents by receiving the best matches, read live, with their
 * question. No document text is kept; only words, titles and the summaries
 * of the most used documents.
 */
class KnowledgeBase
{
    /** Candidates the database returns before they are ranked here. */
    private const CANDIDATES = 60;

    /** Documents read live for a one-shot agent, at most. */
    private const READ_FOR_CONTEXT = 3;

    /** What an agent receives of each document read live. */
    private const CONTEXT_CHARACTERS = 1200;

    /** Words that say nothing about what to look for. */
    private const STOPWORDS = [
        'que', 'qué', 'como', 'cómo', 'cual', 'cuál', 'cuando', 'cuándo', 'donde', 'dónde', 'para', 'por', 'con', 'sin', 'sobre',
        'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'este', 'esta', 'esto', 'ese', 'esa', 'eso', 'hay', 'hace', 'tiene',
        'tenemos', 'están', 'son', 'fue', 'ser', 'más', 'mas', 'pero', 'porque', 'algo', 'todo', 'nos', 'les', 'sus',
        'the', 'and', 'for', 'with', 'what', 'how',
    ];

    public function __construct(private KnowledgeSync $sync) {}

    /**
     * The documents that best match: title, source and link, and the summary
     * when it is one of the most used.
     *
     * @param  list<KnowledgeSource>  $sources  Empty for all of them.
     * @return list<array{id: int, source: string, title: string, url: ?string, ref: string, summary: ?string}>
     */
    public function search(User $user, string $query, array $sources = [], int $limit = 8): array
    {
        return array_map(fn (KnowledgeDocument $document): array => [
            'id' => $document->id,
            'source' => $document->source->label(),
            'title' => $document->title,
            'url' => $document->url,
            'ref' => $document->ref,
            'summary' => $document->summary,
        ], $this->matches($user, $query, $sources, $limit));
    }

    /**
     * The best matches as a block to put in front of a one-shot agent, or an
     * empty string when nothing matches. The first few are read live (or
     * their summary is used) and counted as used.
     *
     * @param  list<KnowledgeSource>  $sources
     */
    public function context(User $user, string $query, int $limit = 6, array $sources = []): string
    {
        $documents = $this->matches($user, $query, $sources, $limit);

        if ($documents === []) {
            return '';
        }

        $terms = self::terms($query);
        $lines = ['Lo que dicen el archivo de trabajo, Jira, Notion y las reuniones sobre esto. Úsalo si sirve y di de dónde sale:'];

        foreach ($documents as $position => $document) {
            $what = $position < self::READ_FOR_CONTEXT ? $this->gist($document, $terms) : null;
            $lines[] = "- [{$document->source->label()}] {$document->title}".($document->url ? " ({$document->url})" : '').($what ? ": {$what}" : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Count that someone used the document. The most used keep a summary.
     */
    public function viewed(KnowledgeDocument $document): void
    {
        $document->forceFill(['views' => $document->views + 1, 'last_viewed_at' => CarbonImmutable::now()])->save();
    }

    /**
     * How much of each source is indexed: listed, indexed, summarized, and
     * when the last document was indexed. Every source appears, even an empty one.
     *
     * @return list<array{source: string, label: string, total: int, copied: int, summarized: int, last_copied_at: ?string}>
     */
    public function status(User $user): array
    {
        $rows = $user->knowledgeDocuments()
            ->selectRaw('source, count(*) as total, count(indexed_at) as copied, count(summary) as summarized, max(indexed_at) as last_copied_at')
            ->groupBy('source')
            ->get()
            ->keyBy(fn (KnowledgeDocument $row): string => $row->getRawOriginal('source'));

        return array_map(function (KnowledgeSource $source) use ($rows): array {
            $row = $rows->get($source->value);
            $last = $row?->getRawOriginal('last_copied_at');

            return [
                'source' => $source->value,
                'label' => $source->label(),
                'total' => (int) ($row?->getRawOriginal('total') ?? 0),
                'copied' => (int) ($row?->getRawOriginal('copied') ?? 0),
                'summarized' => (int) ($row?->getRawOriginal('summarized') ?? 0),
                'last_copied_at' => $last === null ? null : CarbonImmutable::parse($last)->toIso8601String(),
            ];
        }, KnowledgeSource::cases());
    }

    /**
     * The words worth searching for, lowercase. Jira keys and other words
     * with dashes are kept whole.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', mb_strtolower($query), $matches);

        $terms = array_filter(
            $matches[0],
            fn (string $word): bool => mb_strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true),
        );

        return array_slice(array_values(array_unique($terms)), 0, 12);
    }

    /**
     * @param  list<KnowledgeSource>  $sources
     * @return list<KnowledgeDocument>
     */
    private function matches(User $user, string $query, array $sources, int $limit): array
    {
        $terms = self::terms($query);

        if ($terms === []) {
            return [];
        }

        $candidates = $user->knowledgeDocuments()
            ->when($sources !== [], fn (Builder $documents) => $documents->whereIn('source', $sources))
            ->where(function (Builder $documents) use ($terms): void {
                $this->matching($documents, $terms);

                foreach ($terms as $term) {
                    $documents->orWhereRaw('lower(title) like ?', ['%'.$term.'%']);
                }
            })
            ->limit(self::CANDIDATES)
            ->get();

        return $candidates
            ->map(fn (KnowledgeDocument $document): array => ['document' => $document, 'score' => $this->score($document, $terms)])
            ->filter(fn (array $hit): bool => $hit['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('document')
            ->values()
            ->all();
    }

    /**
     * The database's full-text match on the title and the words: any of
     * them, with Spanish stems on PostgreSQL and natural language on MySQL.
     *
     * @param  Builder<KnowledgeDocument>  $documents
     * @param  list<string>  $terms
     */
    private function matching(Builder $documents, array $terms): void
    {
        $words = array_map(fn (string $term): string => str_replace('-', ' ', $term), $terms);

        match (DB::connection()->getDriverName()) {
            'pgsql' => $documents->whereFullText(['title', 'terms'], implode(' OR ', $words), ['language' => 'spanish', 'mode' => 'websearch']),
            'mysql', 'mariadb' => $documents->whereFullText(['title', 'terms'], implode(' ', $words)),
            default => $documents->where(function (Builder $any) use ($terms): void {
                foreach ($terms as $term) {
                    $any->orWhereRaw('lower(terms) like ?', ['%'.$term.'%']);
                }
            }),
        };
    }

    /**
     * Words in the title weigh most; then how many of the words the document
     * has, and a little for being used and being recent.
     *
     * @param  list<string>  $terms
     */
    private function score(KnowledgeDocument $document, array $terms): float
    {
        $title = Str::lower(Str::ascii($document->title));
        $index = ' '.Str::lower(Str::ascii((string) $document->terms)).' ';
        $score = 0.0;

        foreach ($terms as $term) {
            $plain = Str::ascii($term);
            $inTitle = str_contains($title, $plain);
            $inIndex = str_contains($index, " {$plain}");

            if ($inTitle || $inIndex) {
                $score += ($inTitle ? 7 : 0) + ($inIndex ? 3 : 0);
            }
        }

        if ($score === 0.0) {
            return 0.0;
        }

        return $score + min(2, log(1 + $document->views, 2)) + ($document->source_updated_at?->greaterThan(now()->subDays(14)) ? 1 : 0);
    }

    /**
     * What an agent receives of one document: its summary, or the part of
     * the text around the first matching word, read live and not kept.
     *
     * @param  list<string>  $terms
     */
    private function gist(KnowledgeDocument $document, array $terms): ?string
    {
        $this->viewed($document);

        if (filled($document->summary)) {
            return $document->summary;
        }

        try {
            $text = $this->sync->text($document);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $folded = Str::lower(Str::ascii($text));
        $position = null;

        foreach ($terms as $term) {
            $found = mb_strpos($folded, Str::ascii($term));

            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }

        $start = max(0, ($position ?? 0) - 200);

        return ($start > 0 ? '…' : '').trim(mb_substr($text, $start, self::CONTEXT_CHARACTERS)).(mb_strlen($text) > $start + self::CONTEXT_CHARACTERS ? '…' : '');
    }
}
