<?php

namespace App\Connectors\Wiki;

use App\Connectors\Docs\EditNotPossible;

/**
 * One change to a page, anchored to text that appears once in its markdown.
 */
final readonly class WikiEdit
{
    public const TYPES = ['replace', 'insert_after', 'insert_before', 'delete', 'append'];

    /**
     * @param  'replace'|'insert_after'|'insert_before'|'delete'|'append'  $type
     * @param  string  $find  Exact text of the page; empty for append.
     */
    public function __construct(
        public string $type,
        public string $find,
        public string $text = '',
    ) {}

    /**
     * The edits, checked against the page: each anchor appears exactly once.
     *
     * @param  list<array{type: string, find?: ?string, text?: ?string}>  $edits
     * @return list<self>
     *
     * @throws EditNotPossible with a message for the assistant.
     */
    public static function planFor(WikiPage $page, array $edits): array
    {
        return array_map(function (array $edit) use ($page): self {
            $wikiEdit = new self($edit['type'], (string) ($edit['find'] ?? ''), (string) ($edit['text'] ?? ''));

            if ($wikiEdit->type === 'append') {
                if (trim($wikiEdit->text) === '') {
                    throw new EditNotPossible('Falta el texto que añadir al final.');
                }

                return $wikiEdit;
            }

            $found = count($page->locate($wikiEdit->find));

            if ($found === 0) {
                throw new EditNotPossible("No encuentro «{$wikiEdit->find}» en la página. Cítalo exactamente como aparece en su markdown.");
            }

            if ($found > 1) {
                throw new EditNotPossible("«{$wikiEdit->find}» aparece {$found} veces. Cita un fragmento más largo que sea único.");
            }

            return $wikiEdit;
        }, $edits);
    }

    /**
     * What the anchored text becomes.
     */
    public function replacement(): string
    {
        return match ($this->type) {
            'replace' => $this->text,
            'delete' => '',
            'insert_after' => $this->find."\n".$this->text,
            'insert_before' => $this->text."\n".$this->find,
            default => $this->find,
        };
    }

    /**
     * The change on its page, with some context, as the screen shows it.
     *
     * @return array{type: string, before: string, removed: string, inserted: string, after: string}
     */
    public function preview(WikiPage $page): array
    {
        if ($this->type === 'append') {
            return ['type' => 'insert', 'before' => mb_substr(rtrim($page->markdown), -120), 'removed' => '', 'inserted' => "\n".$this->text, 'after' => ''];
        }

        $offset = $page->locate($this->find)[0];
        $before = substr($page->markdown, max(0, $offset - 160), min($offset, 160));
        $after = substr($page->markdown, $offset + strlen($this->find), 160);

        return match ($this->type) {
            'insert_after' => ['type' => 'insert', 'before' => mb_scrub($before).$this->find, 'removed' => '', 'inserted' => "\n".$this->text, 'after' => mb_scrub($after)],
            'insert_before' => ['type' => 'insert', 'before' => mb_scrub($before), 'removed' => '', 'inserted' => $this->text."\n", 'after' => $this->find.mb_scrub($after)],
            default => ['type' => $this->type, 'before' => mb_scrub($before), 'removed' => $this->find, 'inserted' => $this->replacement(), 'after' => mb_scrub($after)],
        };
    }

    /**
     * @return array{type: string, find: string, text: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'find' => $this->find, 'text' => $this->text];
    }
}
