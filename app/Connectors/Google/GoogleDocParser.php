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

        $blocks = $this->blocks($data['body']['content'] ?? [], $data['lists'] ?? [], $this->images($data));

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
     * @param  array<string, string>  $images
     * @return list<array<string, mixed>>
     */
    private function blocks(array $content, array $lists, array $images = []): array
    {
        $blocks = [];

        foreach ($content as $element) {
            if ($this->characters > self::MAX_CHARACTERS) {
                $blocks[] = ['type' => 'truncated'];

                break;
            }

            if (isset($element['paragraph'])) {
                array_push($blocks, ...$this->paragraph($element['paragraph'], $lists, $images));
            } elseif (isset($element['table'])) {
                $blocks[] = [
                    'type' => 'table',
                    'rows' => array_map(fn (array $row): array => array_map(
                        fn (array $cell): array => $this->blocks($cell['content'] ?? [], $lists, $images),
                        $row['tableCells'] ?? [],
                    ), $element['table']['tableRows'] ?? []),
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function images(array $data): array
    {
        $images = [];

        foreach ($data['inlineObjects'] ?? [] as $id => $object) {
            $uri = $object['inlineObjectProperties']['embeddedObject']['imageProperties']['contentUri'] ?? null;

            if (is_string($id) && is_string($uri) && preg_match('~^https://~i', $uri)) {
                $images[$id] = $uri;
            }
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, mixed>  $lists
     * @param  array<string, string>  $images
     * @return list<array<string, mixed>>
     */
    private function paragraph(array $paragraph, array $lists, array $images): array
    {
        $runs = [];
        $pictures = [];
        $hasRule = false;

        foreach ($paragraph['elements'] ?? [] as $element) {
            $objectId = $element['inlineObjectElement']['inlineObjectId'] ?? null;

            if (is_string($objectId) && isset($images[$objectId])) {
                $pictures[] = ['type' => 'image', 'src' => $images[$objectId]];
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

        $blocks = $pictures;

        if ($runs === [] && $hasRule) {
            $blocks[] = ['type' => 'rule'];
        } elseif ($runs !== [] || ($pictures === [] && ! $hasRule)) {
            $style = $paragraph['paragraphStyle']['namedStyleType'] ?? 'NORMAL_TEXT';

            if (isset($paragraph['bullet'])) {
                $level = (int) ($paragraph['bullet']['nestingLevel'] ?? 0);
                $glyph = $lists[$paragraph['bullet']['listId']]['listProperties']['nestingLevels'][$level] ?? [];

                $blocks[] = [
                    'type' => 'list_item',
                    'level' => $level,
                    'ordered' => isset($glyph['glyphType']) && ! in_array($glyph['glyphType'], ['GLYPH_TYPE_UNSPECIFIED', 'NONE'], true),
                    'runs' => $runs,
                ];
            } else {
                $blocks[] = match (true) {
                    $style === 'TITLE' => ['type' => 'heading', 'level' => 0, 'runs' => $runs],
                    $style === 'SUBTITLE' => ['type' => 'heading', 'level' => 6, 'runs' => $runs],
                    str_starts_with($style, 'HEADING_') => ['type' => 'heading', 'level' => (int) substr($style, 8), 'runs' => $runs],
                    default => ['type' => 'paragraph', 'runs' => $runs],
                };
            }
        }

        return $blocks;
    }

    private function safeUrl(?string $url): ?string
    {
        return $url !== null && preg_match('~^(https?://|mailto:)~i', $url) ? $url : null;
    }
}
