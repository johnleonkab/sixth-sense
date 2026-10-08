<?php

namespace App\Connectors\Docs;

/**
 * A document's content: blocks to render it and indexed text to edit it.
 *
 * Blocks: {type: heading|paragraph|list_item|table|rule|image, level?, ordered?,
 * runs?: list<{text, bold?, italic?, underline?, strike?, link?}>, rows?}.
 */
final class Document
{
    /** @var ?list<int> Provider index where each character starts, plus the end. */
    private ?array $indexes = null;

    private ?string $text = null;

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{text: string, start: int}>  $runs  Text as stored by the provider, with the index it starts at.
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $account,
        public readonly string $title,
        public readonly ?string $revisionId,
        public readonly array $blocks,
        public readonly array $runs,
        public readonly ?string $url = null,
        /** False for files that are only read, like Word files kept in Drive. */
        public readonly bool $editable = true,
    ) {}

    public function plainText(): string
    {
        return $this->text ??= implode('', array_column($this->runs, 'text'));
    }

    /**
     * Character ranges where `$find` appears. Whitespace in `$find` matches any
     * whitespace, so line breaks or double spaces do not get in the way.
     *
     * @return list<array{from: int, to: int}>
     */
    public function locate(string $find): array
    {
        $pattern = '~'.preg_replace('/\s+/u', '\\s+', preg_quote(trim($find), '~')).'~u';
        $text = $this->plainText();

        if (trim($find) === '' || ! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        return array_map(function (array $match) use ($text): array {
            $from = mb_strlen(substr($text, 0, $match[1]));

            return ['from' => $from, 'to' => $from + mb_strlen($match[0])];
        }, $matches[0]);
    }

    /**
     * Provider index of a character offset in the plain text.
     */
    public function indexAt(int $character): int
    {
        $this->indexes ??= $this->buildIndexes();

        return $this->indexes[min($character, count($this->indexes) - 1)];
    }

    /**
     * @return list<int>
     */
    private function buildIndexes(): array
    {
        $indexes = [];
        $last = 1;

        foreach ($this->runs as $run) {
            $index = $run['start'];

            foreach (mb_str_split($run['text']) as $character) {
                $indexes[] = $index;
                // Google Docs counts in UTF-16 code units: characters beyond the BMP take two.
                $index += strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')) / 2;
            }

            $last = $index;
        }

        $indexes[] = $last;

        return $indexes;
    }
}
