<?php

namespace App\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Searches the local copy of the archive, Jira and Notion. Every agent uses
 * this same search: the voice through a tool, and the one-shot agents by
 * receiving the best excerpts with their question.
 */
class KnowledgeBase
{
    /** Candidates the database returns before they are ranked here. */
    private const CANDIDATES = 60;

    /** Words that say nothing about what to look for. */
    private const STOPWORDS = [
        'que', 'qué', 'como', 'cómo', 'cual', 'cuál', 'cuando', 'cuándo', 'donde', 'dónde', 'para', 'por', 'con', 'sin', 'sobre',
        'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'este', 'esta', 'esto', 'ese', 'esa', 'eso', 'hay', 'hace', 'tiene',
        'tenemos', 'están', 'esta', 'son', 'fue', 'ser', 'más', 'mas', 'pero', 'porque', 'algo', 'todo', 'nos', 'les', 'sus',
        'the', 'and', 'for', 'with', 'what', 'how',
    ];

    /**
     * The documents that best match, with an excerpt around the match.
     *
     * @param  list<KnowledgeSource>  $sources  Empty for all of them.
     * @return list<array{id: int, source: string, title: string, url: ?string, ref: string, excerpt: string}>
     */
    public function search(User $user, string $query, array $sources = [], int $limit = 8): array
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
            ->map(fn (array $hit): array => [
                'id' => $hit['document']->id,
                'source' => $hit['document']->source->label(),
                'title' => $hit['document']->title,
                'url' => $hit['document']->url,
                'ref' => $hit['document']->ref,
                'excerpt' => $this->excerpt((string) $hit['document']->content, $terms),
            ])
            ->values()
            ->all();
    }

    /**
     * The best excerpts as a block to put in front of a one-shot agent, or an
     * empty string when nothing matches.
     *
     * @param  list<KnowledgeSource>  $sources
     */
    public function context(User $user, string $query, int $limit = 6, array $sources = []): string
    {
        $hits = $this->search($user, $query, $sources, $limit);

        if ($hits === []) {
            return '';
        }

        $lines = ['Lo que dicen el archivo de trabajo, Jira y Notion sobre esto. Úsalo si sirve y di de dónde sale:'];

        foreach ($hits as $hit) {
            $lines[] = "- [{$hit['source']}] {$hit['title']}".($hit['url'] ? " ({$hit['url']})" : '').": {$hit['excerpt']}";
        }

        return implode("\n", $lines);
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
     * The database's full-text match: any of the words, with Spanish stems
     * on PostgreSQL and natural language mode on MySQL.
     *
     * @param  Builder<KnowledgeDocument>  $documents
     * @param  list<string>  $terms
     */
    private function matching(Builder $documents, array $terms): void
    {
        $words = array_map(fn (string $term): string => str_replace('-', ' ', $term), $terms);

        match (DB::connection()->getDriverName()) {
            'pgsql' => $documents->whereFullText(['title', 'content'], implode(' OR ', $words), ['language' => 'spanish', 'mode' => 'websearch']),
            'mysql', 'mariadb' => $documents->whereFullText(['title', 'content'], implode(' ', $words)),
            default => $documents->where(function (Builder $any) use ($terms): void {
                foreach ($terms as $term) {
                    $any->orWhereRaw('lower(content) like ?', ['%'.$term.'%']);
                }
            }),
        };
    }

    /**
     * Matches in the title weigh most; then how many of the words appear and
     * how often, so a long document does not win by length alone.
     *
     * @param  list<string>  $terms
     */
    private function score(KnowledgeDocument $document, array $terms): float
    {
        $title = Str::lower(Str::ascii($document->title));
        $content = Str::lower(Str::ascii((string) $document->content));
        $score = 0.0;

        foreach ($terms as $term) {
            $plain = Str::ascii($term);
            $inTitle = str_contains($title, $plain);
            $count = substr_count($content, $plain);

            if ($inTitle || $count > 0) {
                $score += 2 + ($inTitle ? 5 : 0) + min(5, log(1 + $count, 2));
            }
        }

        return $score;
    }

    /**
     * About 300 characters around the first word that appears, or the start.
     *
     * @param  list<string>  $terms
     */
    private function excerpt(string $content, array $terms): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $content));

        if ($text === '') {
            return 'Todavía sin copiar: ábrelo para leerlo.';
        }

        $folded = Str::lower(Str::ascii($text));
        $position = null;

        foreach ($terms as $term) {
            $found = mb_strpos($folded, Str::ascii($term));

            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }

        $start = max(0, ($position ?? 0) - 100);
        $excerpt = mb_substr($text, $start, 300);

        return ($start > 0 ? '…' : '').trim($excerpt).(mb_strlen($text) > $start + 300 ? '…' : '');
    }
}
