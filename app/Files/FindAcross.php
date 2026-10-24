<?php

namespace App\Files;

use App\Connectors\Google\GoogleDriveFiles;
use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiPageSummary;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Looks for a file in Google Drive and Notion. First by the terms.
 * When no title matches, the latest 50 of each, and the names that fit
 * the conversation come first.
 */
class FindAcross
{
    /** @var list<string> */
    private const SKIP = ['el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'al', 'que', 'con', 'por', 'para', 'como', 'este', 'esta', 'eso', 'esa'];

    public function __construct(private GoogleDriveFiles $drive, private Wiki $wiki) {}

    /**
     * @param  list<string>  $queries
     * @return array{widened: bool, files: list<array{ref: string, title: string, kind: string, source: string, account: string, url: ?string}>}
     */
    public function find(User $user, array $queries, string $context = ''): array
    {
        $queries = array_values(array_filter(array_map(trim(...), $queries), fn (string $query): bool => $query !== ''));
        $queries = array_slice($queries, 0, 5);
        $drive = $this->drive->search($user, $queries);
        $notion = $this->notion($user, $queries);
        $hits = [...$this->driveRows($drive), ...$notion];
        $matched = array_values(array_filter($hits, fn (array $file): bool => $this->matches($file['title'], $queries, $context)));

        if ($matched !== []) {
            return ['widened' => false, 'files' => array_slice($matched, 0, 15)];
        }

        $recent = [
            ...$this->driveRows($this->drive->recent($user)),
            ...$this->notion($user, []),
        ];
        $byName = array_values(array_filter($recent, fn (array $file): bool => $this->matches($file['title'], $queries, $context)));
        $rest = array_values(array_filter($recent, fn (array $file): bool => ! $this->matches($file['title'], $queries, $context)));

        return ['widened' => true, 'files' => array_slice([...$byName, ...$hits, ...$rest], 0, 50)];
    }

    /**
     * @param  list<array{ref: string, title: string, kind: string, account: string, url: ?string, modified_at: ?string}>  $files
     * @return list<array{ref: string, title: string, kind: string, source: string, account: string, url: ?string}>
     */
    private function driveRows(array $files): array
    {
        return array_map(fn (array $file): array => [
            'ref' => $file['ref'],
            'title' => $file['title'],
            'kind' => $file['kind'],
            'source' => 'Google Drive',
            'account' => $file['account'],
            'url' => $file['url'],
        ], $files);
    }

    /**
     * With no queries, the latest pages. With queries, a search for each.
     *
     * @param  list<string>  $queries
     * @return list<array{ref: string, title: string, kind: string, source: string, account: string, url: ?string}>
     */
    private function notion(User $user, array $queries): array
    {
        $pages = $queries === []
            ? $this->wiki->search($user, null, 50)
            : array_merge(...array_map(fn (string $query): array => $this->wiki->search($user, $query, 15), $queries));

        $rows = [];

        foreach ($pages as $page) {
            $rows[$page->ref] ??= $this->page($page);
        }

        return array_values($rows);
    }

    /**
     * @return array{ref: string, title: string, kind: string, source: string, account: string, url: ?string}
     */
    private function page(WikiPageSummary $page): array
    {
        return [
            'ref' => $page->ref,
            'title' => $page->title,
            'kind' => 'notion',
            'source' => 'Notion',
            'account' => $page->workspace,
            'url' => $page->url,
        ];
    }

    /**
     * @param  list<string>  $queries
     */
    private function matches(string $title, array $queries, string $context): bool
    {
        $title = Str::lower(Str::ascii($title));

        foreach ($queries as $query) {
            $query = Str::lower(Str::ascii(trim($query)));

            if ($query !== '' && str_contains($title, $query)) {
                return true;
            }
        }

        foreach ($this->words($queries, $context) as $word) {
            if (str_contains($title, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $queries
     * @return list<string>
     */
    private function words(array $queries, string $context): array
    {
        $words = [];

        foreach ([...$queries, $context] as $text) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', Str::ascii(Str::lower($text))) ?: [] as $word) {
                if (strlen($word) >= 3 && ! in_array($word, self::SKIP, true)) {
                    $words[$word] = $word;
                }
            }
        }

        return array_values($words);
    }
}
