<?php

use App\Connectors\Google\GoogleDocParser;

function paragraph(int $start, array $runs, string $style = 'NORMAL_TEXT', ?array $bullet = null): array
{
    $elements = [];

    foreach ($runs as [$text, $textStyle]) {
        $elements[] = ['startIndex' => $start, 'textRun' => ['content' => $text, 'textStyle' => $textStyle]];
        $start += mb_strlen($text);
    }

    return ['paragraph' => array_filter(['elements' => $elements, 'paragraphStyle' => ['namedStyleType' => $style], 'bullet' => $bullet])];
}

test('it keeps headings, emphasis, safe links, lists and tables', function () {
    $document = (new GoogleDocParser)->parse([
        'documentId' => 'doc1',
        'title' => 'PRD prepago',
        'revisionId' => 'rev-9',
        'lists' => ['l1' => ['listProperties' => ['nestingLevels' => [['glyphType' => 'DECIMAL']]]]],
        'body' => ['content' => [
            ['sectionBreak' => []],
            paragraph(1, [["Propuesta\n", []]], 'HEADING_1'),
            paragraph(11, [['Ver ', []], ['Figma', ['link' => ['url' => 'https://figma.com/x'], 'underline' => true]], [' y ', []], ['esto', ['link' => ['url' => 'javascript:alert(1)']]], ["\n", []]]),
            paragraph(29, [['Importe ', []], ['total', ['bold' => true]], ["\n", []]], bullet: ['listId' => 'l1']),
            ['table' => ['tableRows' => [['tableCells' => [['content' => [paragraph(45, [["Paso\n", []]])]]]]]]],
        ]],
    ], '3.doc1', 'jhon@work.com');

    expect($document->title)->toBe('PRD prepago')
        ->and($document->revisionId)->toBe('rev-9')
        ->and($document->url)->toBe('https://docs.google.com/document/d/doc1/edit')
        ->and($document->blocks)->toBe([
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Propuesta']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Ver '], ['text' => 'Figma', 'link' => 'https://figma.com/x'], ['text' => ' y '], ['text' => 'esto']]],
            ['type' => 'list_item', 'level' => 0, 'ordered' => true, 'runs' => [['text' => 'Importe '], ['text' => 'total', 'bold' => true]]],
            ['type' => 'table', 'rows' => [[[['type' => 'paragraph', 'runs' => [['text' => 'Paso']]]]]]],
        ])
        ->and($document->plainText())->toBe("Propuesta\nVer Figma y esto\nImporte total\nPaso\n")
        ->and($document->indexAt(mb_strpos($document->plainText(), 'total')))->toBe(37);
});
