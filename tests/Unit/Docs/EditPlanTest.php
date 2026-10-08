<?php

use App\Connectors\Docs\Document;
use App\Connectors\Docs\DocumentEdit;
use App\Connectors\Docs\DocumentLinks;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Docs\EditPlan;

function documentWith(string ...$paragraphs): Document
{
    $runs = [];
    $index = 1;

    foreach ($paragraphs as $paragraph) {
        $runs[] = ['text' => $paragraph."\n", 'start' => $index];
        $index += strlen(mb_convert_encoding($paragraph."\n", 'UTF-16LE', 'UTF-8')) / 2;
    }

    return new Document('7.doc', 'jhon@work.com', 'PRD', 'rev-1', [], $runs, 'https://docs.google.com/document/d/doc/edit');
}

test('positions are counted in utf-16 like google docs', function () {
    $document = documentWith('Hola 👋 equipo', 'Recordatorio 24 horas antes.');

    // The emoji takes two units, so text after it is one index further than its character offset.
    expect($document->indexAt(mb_strpos($document->plainText(), 'equipo')))->toBe(9)
        ->and($document->indexAt(mb_strpos($document->plainText(), 'Recordatorio')))->toBe(16);
});

test('a replacement deletes the text and inserts the new one at the same place', function () {
    $plan = EditPlan::for(documentWith('Objetivo.', 'Recordatorio de pago 24 horas antes del evento.'), [
        new DocumentEdit('replace', '24 horas', '48 horas'),
    ]);

    expect($plan->operations)->toBe([
        ['type' => 'delete', 'start' => 32, 'end' => 34],
        ['type' => 'insert', 'index' => 32, 'text' => '48'],
    ])
        ->and($plan->revisionId)->toBe('rev-1')
        ->and($plan->changes[0])->toBe([
            'type' => 'replace',
            'before' => 'Recordatorio de pago ',
            'removed' => '24',
            'inserted' => '48',
            'after' => ' horas antes del evento.',
        ]);
});

test('replacing a whole sentence only touches what actually changes', function () {
    $plan = EditPlan::for(documentWith('Recordatorio de pago 24 horas antes del evento.'), [
        new DocumentEdit('replace', 'Recordatorio de pago 24 horas antes del evento.', 'Recordatorio de pago 48 horas antes del evento.'),
    ]);

    expect($plan->changes[0]['removed'])->toBe('24')
        ->and($plan->changes[0]['inserted'])->toBe('48')
        ->and($plan->operations[0])->toBe(['type' => 'delete', 'start' => 22, 'end' => 24]);
});

test('several edits are applied from the end so earlier positions stay valid', function () {
    $plan = EditPlan::for(documentWith('Uno dos tres.'), [
        new DocumentEdit('insert_after', 'Uno', ' y medio'),
        new DocumentEdit('delete', ' tres'),
    ]);

    expect(array_column($plan->operations, 'type'))->toBe(['delete', 'insert'])
        ->and($plan->operations[0]['start'])->toBeGreaterThan($plan->operations[1]['index']);
});

test('whitespace in the quoted text matches any whitespace in the document', function () {
    expect(documentWith('Reembolso parcial', 'automático')->locate('parcial automático'))->toHaveCount(1);
});

test('text that is not in the document can not be edited', function () {
    EditPlan::for(documentWith('Hola'), [new DocumentEdit('replace', 'Adiós', 'Hasta luego')]);
})->throws(EditNotPossible::class, 'No encuentro el texto');

test('ambiguous text has to be quoted more precisely', function () {
    EditPlan::for(documentWith('paso 3', 'paso 3'), [new DocumentEdit('delete', 'paso 3')]);
})->throws(EditNotPossible::class, 'aparece 2 veces');

test('overlapping edits are refused', function () {
    EditPlan::for(documentWith('Uno dos tres'), [new DocumentEdit('delete', 'Uno dos'), new DocumentEdit('delete', 'dos tres')]);
})->throws(EditNotPossible::class, 'se solapan');

test('links to google docs are found in free text', function () {
    $text = 'El PRD: https://docs.google.com/document/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/edit?usp=sharing y otra vez https://docs.google.com/document/u/1/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/view';

    expect(DocumentLinks::in($text))->toBe([['id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz', 'url' => 'https://docs.google.com/document/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/edit']])
        ->and(DocumentLinks::idFrom('1AbCdEfGhIjKlMnOpQrStUvWxYz'))->toBe('1AbCdEfGhIjKlMnOpQrStUvWxYz')
        ->and(DocumentLinks::idFrom('no es un documento'))->toBeNull();
});
