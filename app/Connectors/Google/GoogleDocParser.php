<?php

namespace App\Connectors\Google;

use App\Connectors\Docs\Document;

/**
 * Turns the Docs API structure into renderable blocks and indexed text.
 * Only text, basic styles, lists and tables are kept: nothing executable.
 */
class GoogleDocParser
{
    /** Stop after this much text: the rest is not rendered nor sent to the assistant. */
    private const MAX_CHARACTERS = 80_000;

    /** @var list<array{text: string, start: int}> */
    private array $runs = [];

    private int $characters = 0;

    /**
     * @param  array<string, mixed>  $data
     */
    public function parse(array $data, string $ref, string $account): Document
    {
        $this->runs = [];
        $this->characters = 0;

        $blocks = $this->blocks($data['body']['content'] ?? [], $data['lists'] ?? []);

        return new Document(
            ref: $ref,
            account: $account,
            title: $data['title'] ?? '(Sin título)',
            revisionId: $data['revisionId'] ?? null,
            blocks: $blocks,
            runs: $this->runs,
            url: 'https://docs.google.com/document/d/'.$data['documentId'].'/edit',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $lists
     * @return list<array<string, mixed>>
     */
    private function blocks(array $content, array $lists): array
    {
        $blocks = [];

        foreach ($content as $element) {
            if ($this->characters > self::MAX_CHARACTERS) {
                $blocks[] = ['type' => 'truncated'];

                break;
            }

            if (isset($element['paragraph'])) {
                $blocks[] = $this->paragraph($element['paragraph'], $lists);
            } elseif (isset($element['table'])) {
                $blocks[] = [
                    'type' => 'table',
                    'rows' => array_map(fn (array $row): array => array_map(
                        fn (array $cell): array => $this->blocks($cell['content'] ?? [], $lists),
                        $row['tableCells'] ?? [],
                    ), $element['table']['tableRows'] ?? []),
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, mixed>  $lists
     * @return array<string, mixed>
     */
    private function paragraph(array $paragraph, array $lists): array
    {
        $runs = [];
        $hasImage = false;
        $hasRule = false;

        foreach ($paragraph['elements'] ?? [] as $element) {
            if (isset($element['inlineObjectElement'])) {
                $hasImage = true;
            }

            if (isset($element['horizontalRule'])) {
                $hasRule = true;
            }

            if (! isset($element['textRun']['content'])) {
                continue;
            }

            $text = $element['textRun']['content'];
            $this->runs[] = ['text' => $text, 'start' => (int) ($element['startIndex'] ?? 0)];
            $this->characters += mb_strlen($text);

            $style = $element['textRun']['textStyle'] ?? [];
            $visible = rtrim($text, "\n");

            if ($visible !== '') {
                $runs[] = array_filter([
                    'text' => $visible,
                    'bold' => $style['bold'] ?? null,
                    'italic' => $style['italic'] ?? null,
                    'underline' => ($style['underline'] ?? false) && ! isset($style['link']) ? true : null,
                    'strike' => $style['strikethrough'] ?? null,
                    'link' => $this->safeUrl($style['link']['url'] ?? null),
                ], fn (mixed $value): bool => $value !== null && $value !== false);
            }
        }

        if ($runs === [] && $hasRule) {
            return ['type' => 'rule'];
        }

        if ($runs === [] && $hasImage) {
            return ['type' => 'image'];
        }

        $style = $paragraph['paragraphStyle']['namedStyleType'] ?? 'NORMAL_TEXT';

        if (isset($paragraph['bullet'])) {
            $level = (int) ($paragraph['bullet']['nestingLevel'] ?? 0);
            $glyph = $lists[$paragraph['bullet']['listId']]['listProperties']['nestingLevels'][$level] ?? [];

            return [
                'type' => 'list_item',
                'level' => $level,
                'ordered' => isset($glyph['glyphType']) && ! in_array($glyph['glyphType'], ['GLYPH_TYPE_UNSPECIFIED', 'NONE'], true),
                'runs' => $runs,
            ];
        }

        return match (true) {
            $style === 'TITLE' => ['type' => 'heading', 'level' => 0, 'runs' => $runs],
            $style === 'SUBTITLE' => ['type' => 'heading', 'level' => 6, 'runs' => $runs],
            str_starts_with($style, 'HEADING_') => ['type' => 'heading', 'level' => (int) substr($style, 8), 'runs' => $runs],
            default => ['type' => 'paragraph', 'runs' => $runs],
        };
    }

    private function safeUrl(?string $url): ?string
    {
        return $url !== null && preg_match('~^(https?://|mailto:)~i', $url) ? $url : null;
    }
}
