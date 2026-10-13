<?php

use App\Connectors\Jira\Adf;
use App\Connectors\Jira\JiraRules;

test('jira rich text becomes the blocks the screen shows and plain text for the assistant', function () {
    $doc = ['type' => 'doc', 'version' => 1, 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Acceptance criteria']]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'mention', 'attrs' => ['id' => 'u-1', 'text' => '@John León']],
            ['type' => 'text', 'text' => ' revisa '],
            ['type' => 'text', 'text' => 'esto', 'marks' => [['type' => 'strong']]],
            ['type' => 'text', 'text' => ' y el ', 'marks' => []],
            ['type' => 'text', 'text' => 'Figma', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://figma.com/x']]]],
            ['type' => 'text', 'text' => 'malo', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
        ]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Uno']]],
                ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Dentro']]]]]]],
            ]],
        ]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
    ]];

    $blocks = Adf::toBlocks($doc);

    expect($blocks[0])->toBe(['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Acceptance criteria']]])
        ->and($blocks[1]['runs'])->toBe([
            ['text' => '@John León', 'bold' => true], ['text' => ' revisa '], ['text' => 'esto', 'bold' => true], ['text' => ' y el '],
            ['text' => 'Figma', 'link' => 'https://figma.com/x'], ['text' => 'malo'],
        ])
        ->and($blocks[2])->toBe(['type' => 'list_item', 'level' => 0, 'ordered' => false, 'runs' => [['text' => 'Uno']]])
        ->and($blocks[3])->toMatchArray(['type' => 'list_item', 'level' => 1])
        ->and($blocks[4])->toBe(['type' => 'code', 'text' => 'echo 1;'])
        ->and(Adf::mentions($doc))->toBe(['u-1'])
        ->and(Adf::toText($doc))->toContain('@John León revisa esto y el Figmamalo')->toContain('- Uno');
});

test('markdown becomes jira rich text, with real mentions', function () {
    $doc = Adf::fromMarkdown("## Context\n@Marta Ruiz revisa **esto** con `code` y [Figma](https://figma.com/x)\n\n- Uno\n- Dos\n\n1. Primero", ['Marta Ruiz' => 'u-marta', 'Marta' => 'u-other']);

    expect($doc['content'][0])->toBe(['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Context']]])
        ->and($doc['content'][1]['content'])->toBe([
            ['type' => 'mention', 'attrs' => ['id' => 'u-marta', 'text' => '@Marta Ruiz']],
            ['type' => 'text', 'text' => ' revisa '],
            ['type' => 'text', 'text' => 'esto', 'marks' => [['type' => 'strong']]],
            ['type' => 'text', 'text' => ' con '],
            ['type' => 'text', 'text' => 'code', 'marks' => [['type' => 'code']]],
            ['type' => 'text', 'text' => ' y '],
            ['type' => 'text', 'text' => 'Figma', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://figma.com/x']]]],
        ])
        ->and($doc['content'][2]['type'])->toBe('bulletList')
        ->and($doc['content'][2]['content'])->toHaveCount(2)
        ->and($doc['content'][3]['type'])->toBe('orderedList');
});

test('the rules say which projects, which jql to start from and how dependencies are linked', function () {
    $rules = new JiraRules(JiraRules::template());

    expect($rules->projects())->toBe(['CPD'])
        ->and($rules->baseJql())->toBe('project = CPD AND statusCategory != Done ORDER BY updated DESC')
        ->and($rules->linkType())->toBe('Blocks')
        ->and($rules->sections())->toContain('User stories', 'Dependencias')
        ->and($rules->forAssistant())->not->toContain('<!--');

    $custom = new JiraRules("## Proyectos y filtros\n- Proyectos: CPD, MKT y OPS\n## Dependencias\n- Tipo de enlace: Depends");

    expect($custom->projects())->toBe(['CPD', 'MKT', 'OPS'])
        ->and($custom->baseJql())->toBeNull()
        ->and($custom->linkType())->toBe('Depends');
});
