<?php

namespace App\Connectors\Notion;

/**
 * Notion's enhanced markdown to the blocks the screen renders (the same as a
 * Google Doc's): headings, paragraphs, lists and to-dos, quotes, code,
 * tables, dividers and links. Only text and safe links are kept.
 *
 * @see https://developers.notion.com/guides/data-apis/enhanced-markdown
 */
class NotionMarkdown
{
    private const MAX_BLOCKS = 1500;

    /**
     * @return list<array<string, mixed>>
     */
    public static function blocks(string $markdown): array
    {
        $lines = preg_split('/\r?\n/', $markdown) ?: [];
        $blocks = [];

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            if (count($blocks) >= self::MAX_BLOCKS) {
                $blocks[] = ['type' => 'truncated'];

                break;
            }

            $raw = rtrim($lines[$i]);
            $level = self::indentation($raw);
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            // Fenced code: everything up to the closing fence.
            if (str_starts_with($line, '```')) {
                $code = [];

                for ($i++; $i < $count && ! str_starts_with(trim($lines[$i]), '```'); $i++) {
                    $code[] = $lines[$i];
                }

                $blocks[] = ['type' => 'code', 'text' => implode("\n", $code)];

                continue;
            }

            // A table spans lines until it closes.
            if (str_starts_with($line, '<table')) {
                $html = $line;

                while (! str_contains($html, '</table>') && $i + 1 < $count) {
                    $html .= "\n".$lines[++$i];
                }

                $blocks[] = self::table($html);

                continue;
            }

            $block = self::line($line, $level);

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @return ?array<string, mixed>
     */
    private static function line(string $line, int $level): ?array
    {
        return match (true) {
            $line === '---' => ['type' => 'rule'],
            (bool) preg_match('/^(#{1,6})\s+(.*)$/u', $line, $match) => ['type' => 'heading', 'level' => min(strlen($match[1]), 5), 'runs' => self::runs($match[2])],
            (bool) preg_match('/^[-*]\s+\[( |x|X)\]\s?(.*)$/u', $line, $match) => ['type' => 'list_item', 'level' => $level, 'ordered' => false, 'checked' => strtolower($match[1]) === 'x', 'runs' => self::runs($match[2])],
            (bool) preg_match('/^[-*+]\s+(.*)$/u', $line, $match) => ['type' => 'list_item', 'level' => $level, 'ordered' => false, 'runs' => self::runs($match[1])],
            (bool) preg_match('/^\d+[.)]\s+(.*)$/u', $line, $match) => ['type' => 'list_item', 'level' => $level, 'ordered' => true, 'runs' => self::runs($match[1])],
            (bool) preg_match('/^>\s?(.*)$/u', $line, $match) => ['type' => 'quote', 'runs' => self::runs($match[1])],
            (bool) preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/u', $line) => ['type' => 'image'],
            // Child pages and databases: a link to them.
            (bool) preg_match('/^<(page|database)\s+url="([^"]+)"[^>]*>(.*?)<\/\1>$/u', $line, $match) => ['type' => 'paragraph', 'runs' => [['text' => ($match[1] === 'page' ? 'Página: ' : 'Base de datos: ')], self::link(strip_tags($match[3]) ?: 'Sin título', $match[2])]],
            // Files, PDFs, video, audio: a link to them, with their caption.
            (bool) preg_match('/^<(file|pdf|video|audio)\s+src="([^"]+)"[^>]*>(.*?)<\/\1>$/u', $line, $match) => ['type' => 'paragraph', 'runs' => [self::link(strip_tags($match[3]) ?: ucfirst($match[1]), $match[2])]],
            (bool) preg_match('/^<unknown\s+url="([^"]+)"\s+alt="([^"]*)"\s*\/?>$/u', $line, $match) => ['type' => 'paragraph', 'runs' => [self::link("({$match[2]})", $match[1])]],
            (bool) preg_match('/^<summary>(.*)<\/summary>$/u', $line, $match) => ['type' => 'paragraph', 'runs' => array_map(fn (array $run): array => [...$run, 'bold' => true], self::runs($match[1]))],
            // Layout tags (callouts, toggles, columns, synced blocks) wrap ordinary lines: keep their text.
            (bool) preg_match('/^<\/?[a-z_-]+(\s[^>]*)?>$/u', $line) => null,
            (bool) preg_match('/^<callout[^>]*>(.*)<\/callout>$/u', $line, $match) => ['type' => 'quote', 'runs' => self::runs($match[1])],
            default => ['type' => 'paragraph', 'runs' => self::runs(preg_replace('/^<(details|callout|column|summary)[^>]*>|<\/(details|callout|column|summary)>$/u', '', $line) ?? $line)],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(string $html): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/su', $html, $rows);

        return ['type' => 'table', 'rows' => array_map(function (string $row): array {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/su', $row, $cells);

            return array_map(fn (string $cell): array => [['type' => 'paragraph', 'runs' => self::runs(trim($cell))]], $cells[1]);
        }, $rows[1])];
    }

    /**
     * Inline formatting to runs: bold, italic, strikethrough, code and links.
     *
     * @return list<array<string, mixed>>
     */
    private static function runs(string $text): array
    {
        $text = (string) preg_replace('/<br\s*\/?>/iu', "\n", $text);
        // Mentions of pages and people read as their name; pages link to themselves.
        $text = (string) preg_replace('/<mention-(?:page|database)\s+url="([^"]+)"[^>]*>(.*?)<\/mention-(?:page|database)>/u', '[$2]($1)', $text);
        $text = (string) preg_replace('/<mention-user[^>]*>(.*?)<\/mention-user>/u', '@$1', $text);
        $text = (string) preg_replace('/<mention-date\s+start="([^"]+)"[^>]*\/?>(?:<\/mention-date>)?/u', '$1', $text);
        // Colours, underlines and any other tag: only their text.
        $text = (string) preg_replace('/<\/?[a-z][a-z0-9_-]*(\s[^>]*)?\/?>/iu', '', $text);

        $pattern = '/\*\*(.+?)\*\*|~~(.+?)~~|`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|(?<![*\w])\*(?![\s*])(.+?)(?<!\s)\*(?!\*)|(?<![\w_])_(?![\s_])(.+?)(?<!\s)_(?![\w_])/u';
        $runs = [];
        $cursor = 0;

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];

            if ($offset > $cursor) {
                $runs[] = ['text' => self::unescape(substr($text, $cursor, $offset - $cursor))];
            }

            $runs[] = match (true) {
                ($match[1][0] ?? '') !== '' => ['text' => self::unescape($match[1][0]), 'bold' => true],
                ($match[2][0] ?? '') !== '' => ['text' => self::unescape($match[2][0]), 'strike' => true],
                ($match[3][0] ?? '') !== '' => ['text' => $match[3][0], 'code' => true],
                ($match[4][0] ?? '') !== '' => self::link(self::unescape($match[4][0]), $match[5][0]),
                ($match[6][0] ?? '') !== '' => ['text' => self::unescape($match[6][0]), 'italic' => true],
                default => ['text' => self::unescape($match[7][0] ?? ''), 'italic' => true],
            };

            $cursor = $offset + strlen($whole);
        }

        if ($cursor < strlen($text)) {
            $runs[] = ['text' => self::unescape(substr($text, $cursor))];
        }

        return array_values(array_filter($runs, fn (array $run): bool => $run['text'] !== ''));
    }

    /**
     * @return array{text: string, link?: string}
     */
    private static function link(string $text, string $url): array
    {
        $url = str_starts_with($url, '/') ? "https://www.notion.so{$url}" : $url;

        return preg_match('~^(https?://|mailto:)~i', $url) ? ['text' => $text, 'link' => $url] : ['text' => $text];
    }

    private static function unescape(string $text): string
    {
        return html_entity_decode((string) preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!~|<>])/u', '$1', $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Nesting from leading tabs (Notion) or pairs of spaces.
     */
    private static function indentation(string $line): int
    {
        preg_match('/^[\t ]*/', $line, $match);

        return min(5, substr_count($match[0], "\t") + intdiv(substr_count($match[0], ' '), 2));
    }
}
