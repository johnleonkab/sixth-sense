<?php

namespace App\Connectors\Docs;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Reads a Word file (.docx) into the same blocks as a Google Doc, so a Word
 * file shared from Drive can be shown and read. It can not be edited.
 * Only text, basic styles, lists and tables are kept: nothing executable.
 */
class WordDocumentParser
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Stop after this much text, like Google Docs. */
    private const MAX_CHARACTERS = 80_000;

    /** A file that unpacks into more than this is not read (a zip bomb, or not a document). */
    private const MAX_UNPACKED_BYTES = 30 * 1024 * 1024;

    private DOMXPath $xpath;

    /** @var array<string, int> Style id => heading level (0 title, 6 subtitle). */
    private array $headings = [];

    /** @var array<string, string> Relationship id => URL. */
    private array $links = [];

    /** @var array<string, array<int, bool>> List id => level => ordered. */
    private array $ordered = [];

    /** @var list<array{text: string, start: int}> */
    private array $runs = [];

    private int $characters = 0;

    /**
     * @throws UnreadableDocument
     */
    public function parse(string $bytes, string $ref, string $account, string $title, ?string $url = null): Document
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $bytes);

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new UnreadableDocument('Not a Word file.');
            }

            $document = $this->load($zip, 'word/document.xml') ?? throw new UnreadableDocument('The Word file has no document.');
            $this->headings = $this->headingStyles($this->load($zip, 'word/styles.xml'));
            $this->links = $this->hyperlinks($this->load($zip, 'word/_rels/document.xml.rels'));
            $this->ordered = $this->numbering($this->load($zip, 'word/numbering.xml'));
            $zip->close();
        } finally {
            @unlink($path);
        }

        $this->xpath = $this->xpathFor($document);
        $this->runs = [];
        $this->characters = 0;
        $body = $this->xpath->query('/w:document/w:body')->item(0);

        return new Document(
            ref: $ref,
            account: $account,
            title: preg_replace('/\.docx$/i', '', $title) ?: '(Sin título)',
            revisionId: null,
            blocks: $body instanceof DOMElement ? $this->blocks($body) : [],
            runs: $this->runs,
            url: $url,
            editable: false,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(DOMElement $parent): array
    {
        $blocks = [];

        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::W) {
                continue;
            }

            if ($this->characters > self::MAX_CHARACTERS) {
                $blocks[] = ['type' => 'truncated'];

                break;
            }

            if ($node->localName === 'p') {
                $block = $this->paragraph($node);

                if ($block !== null) {
                    $blocks[] = $block;
                }
            } elseif ($node->localName === 'tbl') {
                $blocks[] = [
                    'type' => 'table',
                    'rows' => array_map(
                        fn (DOMElement $row): array => array_map(fn (DOMElement $cell): array => $this->blocks($cell), $this->children($row, 'tc')),
                        $this->children($node, 'tr'),
                    ),
                ];
            } elseif ($node->localName === 'sdt') {
                // Content controls (a table of contents, a cover page) wrap ordinary paragraphs.
                $content = $this->children($node, 'sdtContent')[0] ?? null;
                $blocks = $content ? [...$blocks, ...$this->blocks($content)] : $blocks;
            }
        }

        return $blocks;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function paragraph(DOMElement $paragraph): ?array
    {
        $runs = [];
        $text = '';

        foreach ($this->xpath->query('./w:r | ./w:hyperlink/w:r | ./w:ins/w:r | ./w:smartTag/w:r', $paragraph) as $run) {
            $hyperlink = $run->parentNode instanceof DOMElement && $run->parentNode->localName === 'hyperlink' ? $run->parentNode : null;
            $content = $this->runText($run);

            if ($content === '') {
                continue;
            }

            $text .= $content;
            $runs[] = array_filter([
                'text' => $content,
                'bold' => $this->isOn($run, 'b'),
                'italic' => $this->isOn($run, 'i'),
                'underline' => $hyperlink === null && $this->xpath->query('./w:rPr/w:u[not(@w:val="none")]', $run)->length > 0,
                'strike' => $this->isOn($run, 'strike'),
                'link' => $hyperlink ? $this->safeUrl($this->links[$hyperlink->getAttributeNS(self::R, 'id')] ?? null) : null,
            ], fn (mixed $value): bool => $value !== null && $value !== false);
        }

        $this->runs[] = ['text' => $text."\n", 'start' => $this->characters];
        $this->characters += mb_strlen($text) + 1;

        if (trim($text) === '') {
            return $this->xpath->query('.//w:drawing | .//w:pict', $paragraph)->length > 0 ? ['type' => 'image'] : null;
        }

        $style = $this->xpath->query('./w:pPr/w:pStyle/@w:val', $paragraph)->item(0)?->nodeValue;
        $list = $this->xpath->query('./w:pPr/w:numPr', $paragraph)->item(0);

        if ($list instanceof DOMElement) {
            $level = (int) $this->xpath->query('./w:ilvl/@w:val', $list)->item(0)?->nodeValue;
            $id = (string) $this->xpath->query('./w:numId/@w:val', $list)->item(0)?->nodeValue;

            if ($id !== '0') {
                return ['type' => 'list_item', 'level' => $level, 'ordered' => $this->ordered[$id][$level] ?? false, 'runs' => $runs];
            }
        }

        if ($style !== null && isset($this->headings[$style])) {
            return ['type' => 'heading', 'level' => $this->headings[$style], 'runs' => $runs];
        }

        return ['type' => 'paragraph', 'runs' => $runs];
    }

    private function runText(DOMElement $run): string
    {
        $text = '';

        foreach ($run->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text .= match ($node->localName) {
                't' => $node->textContent,
                'tab' => "\t",
                'br', 'cr' => "\n",
                'noBreakHyphen' => '-',
                default => '',
            };
        }

        return $text;
    }

    /**
     * Whether a run has a style on: <w:b/>, but not <w:b w:val="0"/>.
     */
    private function isOn(DOMElement $run, string $property): ?bool
    {
        $element = $this->xpath->query("./w:rPr/w:{$property}", $run)->item(0);

        if (! $element instanceof DOMElement) {
            return null;
        }

        return ! in_array($element->getAttributeNS(self::W, 'val'), ['0', 'false', 'off'], true) ?: null;
    }

    /**
     * Built-in styles keep their English name whatever the language of Word.
     *
     * @return array<string, int>
     */
    private function headingStyles(?DOMDocument $styles): array
    {
        if ($styles === null) {
            return [];
        }

        $headings = [];

        foreach ($this->xpathFor($styles)->query('//w:style[@w:type="paragraph"]') as $style) {
            $id = $style->getAttributeNS(self::W, 'styleId');
            $name = mb_strtolower((string) $this->xpathFor($styles)->query('./w:name/@w:val', $style)->item(0)?->nodeValue);

            $level = match (true) {
                $name === 'title' => 0,
                $name === 'subtitle' => 6,
                (bool) preg_match('/^heading ([1-6])$/', $name, $match) => (int) $match[1],
                default => null,
            };

            if ($level !== null) {
                $headings[$id] = $level;
            }
        }

        return $headings;
    }

    /**
     * @return array<string, string>
     */
    private function hyperlinks(?DOMDocument $relationships): array
    {
        $links = [];

        foreach ($relationships?->getElementsByTagName('Relationship') ?? [] as $relationship) {
            if ($relationship->getAttribute('TargetMode') === 'External') {
                $links[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
            }
        }

        return $links;
    }

    /**
     * Which list levels are numbered rather than bulleted.
     *
     * @return array<string, array<int, bool>>
     */
    private function numbering(?DOMDocument $numbering): array
    {
        if ($numbering === null) {
            return [];
        }

        $xpath = $this->xpathFor($numbering);
        $abstract = [];

        foreach ($xpath->query('//w:abstractNum') as $definition) {
            foreach ($xpath->query('./w:lvl', $definition) as $level) {
                $format = $xpath->query('./w:numFmt/@w:val', $level)->item(0)?->nodeValue ?? 'bullet';
                $abstract[$definition->getAttributeNS(self::W, 'abstractNumId')][(int) $level->getAttributeNS(self::W, 'ilvl')] = ! in_array($format, ['bullet', 'none'], true);
            }
        }

        $lists = [];

        foreach ($xpath->query('//w:num') as $list) {
            $abstractId = $xpath->query('./w:abstractNumId/@w:val', $list)->item(0)?->nodeValue;
            $lists[$list->getAttributeNS(self::W, 'numId')] = $abstract[$abstractId] ?? [];
        }

        return $lists;
    }

    /**
     * @return list<DOMElement>
     */
    private function children(DOMElement $parent, string $name): array
    {
        $children = [];

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::W && $node->localName === $name) {
                $children[] = $node;
            }
        }

        return $children;
    }

    /**
     * @throws UnreadableDocument
     */
    private function load(ZipArchive $zip, string $name): ?DOMDocument
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            return null;
        }

        if ($stat['size'] > self::MAX_UNPACKED_BYTES) {
            throw new UnreadableDocument('The Word file is too large.');
        }

        $xml = $zip->getFromName($name);
        $document = new DOMDocument;

        // No network and no entity expansion: the file comes from someone else.
        if ($xml === false || ! @$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new UnreadableDocument("The Word file has an invalid {$name}.");
        }

        return $document;
    }

    private function xpathFor(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::W);

        return $xpath;
    }

    private function safeUrl(?string $url): ?string
    {
        return $url !== null && preg_match('~^(https?://|mailto:)~i', $url) ? $url : null;
    }
}
