<?php

namespace App\Connectors\Jira;

/**
 * Atlassian Document Format, the rich text of Jira descriptions and comments:
 * to the blocks the screen renders, to plain text for the assistant, and
 * from the simple markdown the assistant writes (with real mentions).
 *
 * @see https://developer.atlassian.com/cloud/jira/platform/apis/document/structure/
 */
final class Adf
{
    /**
     * @param  ?array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    public static function toBlocks(?array $document): array
    {
        return self::blocks($document['content'] ?? [], 0);
    }

    /**
     * @param  ?array<string, mixed>  $document
     */
    public static function toText(?array $document): string
    {
        return trim((string) preg_replace("/\n{3,}/", "\n\n", self::text($document['content'] ?? [])));
    }

    /**
     * Account ids mentioned anywhere in it.
     *
     * @param  ?array<string, mixed>  $document
     * @return list<string>
     */
    public static function mentions(?array $document): array
    {
        $found = [];
        $walk = function (array $nodes) use (&$walk, &$found): void {
            foreach ($nodes as $node) {
                if (($node['type'] ?? null) === 'mention' && isset($node['attrs']['id'])) {
                    $found[] = $node['attrs']['id'];
                }

                $walk($node['content'] ?? []);
            }
        };
        $walk($document['content'] ?? []);

        return array_values(array_unique($found));
    }

    /**
     * Markdown (paragraphs, # headings, - and 1. lists, > quotes, ``` code,
     * ---, **bold**, *italic*, `code`, [links](url)) to a document. "@Name"
     * becomes a mention when the name is in $mentions.
     *
     * @param  array<string, string>  $mentions  Display name => account id.
     * @return array<string, mixed>
     */
    public static function fromMarkdown(string $markdown, array $mentions = []): array
    {
        $lines = preg_split('/\r?\n/', trim($markdown)) ?: [];
        $content = [];
        $paragraph = [];

        $flush = function () use (&$paragraph, &$content, $mentions): void {
            if ($paragraph !== []) {
                $content[] = ['type' => 'paragraph', 'content' => self::inline(implode("\n", $paragraph), $mentions)];
                $paragraph = [];
            }
        };

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $line = rtrim($lines[$i]);

            if (trim($line) === '') {
                $flush();

                continue;
            }

            if (str_starts_with(trim($line), '```')) {
                $flush();
                $language = trim(substr(trim($line), 3));
                $code = [];

                for ($i++; $i < $count && ! str_starts_with(trim($lines[$i]), '```'); $i++) {
                    $code[] = $lines[$i];
                }

                $content[] = array_filter(['type' => 'codeBlock', 'attrs' => $language !== '' ? ['language' => $language] : null, 'content' => [['type' => 'text', 'text' => implode("\n", $code) ?: ' ']]]);

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $match)) {
                $flush();
                $content[] = ['type' => 'heading', 'attrs' => ['level' => strlen($match[1])], 'content' => self::inline($match[2], $mentions)];

                continue;
            }

            if (trim($line) === '---') {
                $flush();
                $content[] = ['type' => 'rule'];

                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $match)) {
                $flush();
                $content[] = ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => self::inline($match[1], $mentions)]]];

                continue;
            }

            // A list runs while its lines start the same way.
            if (preg_match('/^\s*([-*]|\d+[.)])\s+/', $line, $marker)) {
                $flush();
                $ordered = ! in_array($marker[1], ['-', '*'], true);
                $items = [];

                for (; $i < $count && preg_match('/^\s*([-*]|\d+[.)])\s+(.*)$/', $lines[$i], $item) && (! in_array($item[1], ['-', '*'], true)) === $ordered; $i++) {
                    $items[] = ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => self::inline($item[2], $mentions)]]];
                }

                $i--;
                $content[] = ['type' => $ordered ? 'orderedList' : 'bulletList', 'content' => $items];

                continue;
            }

            $paragraph[] = $line;
        }

        $flush();

        return ['type' => 'doc', 'version' => 1, 'content' => $content ?: [['type' => 'paragraph', 'content' => []]]];
    }

    /**
     * @param  array<string, string>  $mentions
     * @return list<array<string, mixed>>
     */
    private static function inline(string $text, array $mentions): array
    {
        // Longest names first, so "@Marta Ruiz" wins over "@Marta".
        uksort($mentions, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $names = array_map(fn (string $name): string => preg_quote($name, '/'), array_keys($mentions));
        $mention = $names === [] ? '(?!)' : '@(?:'.implode('|', $names).')';

        $pattern = '/('.$mention.')|\*\*(.+?)\*\*|`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|(?<![*\w])\*(?![\s*])(.+?)(?<!\s)\*(?!\*)|~~(.+?)~~|(\n)/u';
        $nodes = [];
        $cursor = 0;

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];

            if ($offset > $cursor) {
                $nodes[] = ['type' => 'text', 'text' => substr($text, $cursor, $offset - $cursor)];
            }

            $nodes[] = match (true) {
                ($match[1][0] ?? '') !== '' => ['type' => 'mention', 'attrs' => ['id' => $mentions[substr($match[1][0], 1)], 'text' => $match[1][0]]],
                ($match[2][0] ?? '') !== '' => ['type' => 'text', 'text' => $match[2][0], 'marks' => [['type' => 'strong']]],
                ($match[3][0] ?? '') !== '' => ['type' => 'text', 'text' => $match[3][0], 'marks' => [['type' => 'code']]],
                ($match[4][0] ?? '') !== '' => ['type' => 'text', 'text' => $match[4][0], 'marks' => [['type' => 'link', 'attrs' => ['href' => $match[5][0]]]]],
                ($match[6][0] ?? '') !== '' => ['type' => 'text', 'text' => $match[6][0], 'marks' => [['type' => 'em']]],
                ($match[7][0] ?? '') !== '' => ['type' => 'text', 'text' => $match[7][0], 'marks' => [['type' => 'strike']]],
                default => ['type' => 'hardBreak'],
            };

            $cursor = $offset + strlen($whole);
        }

        if ($cursor < strlen($text)) {
            $nodes[] = ['type' => 'text', 'text' => substr($text, $cursor)];
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $nodes, int $level): array
    {
        $blocks = [];

        foreach ($nodes as $node) {
            switch ($node['type'] ?? null) {
                case 'paragraph':
                    $blocks[] = ['type' => 'paragraph', 'runs' => self::runs($node['content'] ?? [])];
                    break;
                case 'heading':
                    $blocks[] = ['type' => 'heading', 'level' => min((int) ($node['attrs']['level'] ?? 2), 5), 'runs' => self::runs($node['content'] ?? [])];
                    break;
                case 'bulletList':
                case 'orderedList':
                case 'taskList':
                    foreach ($node['content'] ?? [] as $item) {
                        $children = $item['content'] ?? [];
                        $first = ($children[0]['type'] ?? null) === 'paragraph' ? array_shift($children) : null;
                        $block = ['type' => 'list_item', 'level' => $level, 'ordered' => $node['type'] === 'orderedList', 'runs' => self::runs($first['content'] ?? ($item['type'] === 'taskItem' ? $item['content'] ?? [] : []))];

                        if (($item['type'] ?? null) === 'taskItem') {
                            $block['checked'] = ($item['attrs']['state'] ?? null) === 'DONE';
                        }

                        $blocks[] = $block;
                        array_push($blocks, ...self::blocks($item['type'] === 'taskItem' ? [] : $children, $level + 1));
                    }
                    break;
                case 'codeBlock':
                    $blocks[] = ['type' => 'code', 'text' => self::text($node['content'] ?? [])];
                    break;
                case 'blockquote':
                case 'panel':
                    $blocks[] = ['type' => 'quote', 'runs' => self::runs(array_merge(...array_map(fn (array $child): array => [...($child['content'] ?? []), ['type' => 'hardBreak']], $node['content'] ?? [])))];
                    break;
                case 'rule':
                    $blocks[] = ['type' => 'rule'];
                    break;
                case 'table':
                    $blocks[] = ['type' => 'table', 'rows' => array_map(
                        fn (array $row): array => array_map(fn (array $cell): array => self::blocks($cell['content'] ?? [], 0), $row['content'] ?? []),
                        $node['content'] ?? [],
                    )];
                    break;
                case 'mediaSingle':
                case 'mediaGroup':
                    $blocks[] = ['type' => 'image'];
                    break;
                default:
                    if (isset($node['content'])) {
                        array_push($blocks, ...self::blocks($node['content'], $level));
                    }
            }
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function runs(array $nodes): array
    {
        $runs = [];

        foreach ($nodes as $node) {
            $run = match ($node['type'] ?? null) {
                'text' => self::marked($node),
                'mention' => ['text' => str_starts_with((string) ($node['attrs']['text'] ?? ''), '@') ? $node['attrs']['text'] : '@'.($node['attrs']['text'] ?? 'alguien'), 'bold' => true],
                'emoji' => ['text' => $node['attrs']['text'] ?? $node['attrs']['shortName'] ?? ''],
                'hardBreak' => ['text' => "\n"],
                'inlineCard' => self::safeLink($node['attrs']['url'] ?? '', $node['attrs']['url'] ?? ''),
                'status' => ['text' => strtoupper((string) ($node['attrs']['text'] ?? '')), 'code' => true],
                'date' => ['text' => isset($node['attrs']['timestamp']) ? date('d/m/Y', (int) ((int) $node['attrs']['timestamp'] / 1000)) : ''],
                default => null,
            };

            if ($run !== null && $run['text'] !== '') {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function marked(array $node): array
    {
        $run = ['text' => (string) ($node['text'] ?? '')];

        foreach ($node['marks'] ?? [] as $mark) {
            match ($mark['type'] ?? null) {
                'strong' => $run['bold'] = true,
                'em' => $run['italic'] = true,
                'strike' => $run['strike'] = true,
                'code' => $run['code'] = true,
                'underline' => $run['underline'] = true,
                'link' => $run = [...$run, ...self::safeLink($run['text'], (string) ($mark['attrs']['href'] ?? ''))],
                default => null,
            };
        }

        return $run;
    }

    /**
     * @return array{text: string, link?: string}
     */
    private static function safeLink(string $text, string $url): array
    {
        return preg_match('~^(https?://|mailto:)~i', $url) ? ['text' => $text, 'link' => $url] : ['text' => $text];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function text(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            $text .= match ($node['type'] ?? null) {
                'text' => $node['text'] ?? '',
                'mention' => str_starts_with((string) ($node['attrs']['text'] ?? ''), '@') ? $node['attrs']['text'] : '@'.($node['attrs']['text'] ?? ''),
                'emoji' => $node['attrs']['text'] ?? '',
                'hardBreak' => "\n",
                'inlineCard' => $node['attrs']['url'] ?? '',
                'listItem' => '- '.self::text($node['content'] ?? []),
                'paragraph', 'heading', 'codeBlock', 'blockquote', 'rule' => self::text($node['content'] ?? [])."\n",
                default => self::text($node['content'] ?? []),
            };
        }

        return $text;
    }
}
