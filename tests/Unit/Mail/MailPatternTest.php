<?php

use App\Connectors\Google\HtmlToText;
use App\Connectors\Mail\InvalidMailPattern;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\MailPattern;

test('invalid regular expressions are rejected', function () {
    MailPattern::compile('pre(pago');
})->throws(InvalidMailPattern::class);

test('it matches ignoring case and returns the match with context', function () {
    $excerpt = MailPattern::compile('pre-?pago')->excerpt('He subido la versión nueva del flujo de PREPAGO para que la revises.');

    expect($excerpt)->toBe([
        'before' => 'He subido la versión nueva del flujo de ',
        'match' => 'PREPAGO',
        'after' => ' para que la revises.',
    ]);
});

test('it matches ignoring accents when the text has them and the pattern does not', function () {
    expect(MailPattern::compile('reunion del jueves')->excerpt('Movemos la reunión del jueves a las 11.')['match'])
        ->toBe('reunion del jueves');
});

test('it returns null when nothing matches', function () {
    expect(MailPattern::compile('factura')->excerpt('Nada que ver'))->toBeNull();
});

test('long texts are cut around the match', function () {
    $excerpt = MailPattern::compile('clave')->excerpt(str_repeat('palabra ', 40).'la clave está aquí '.str_repeat('relleno ', 40));

    expect($excerpt['before'])->toStartWith('…')
        ->and($excerpt['after'])->toEndWith('…')
        ->and(mb_strlen($excerpt['before'].$excerpt['match'].$excerpt['after']))->toBeLessThan(200);
});

test('segments highlight every match even with groups in the pattern', function () {
    expect(MailPattern::compile('(pre)?pago')->segments('Prepago y pago.'))->toBe([
        ['text' => 'Prepago', 'match' => true],
        ['text' => ' y ', 'match' => false],
        ['text' => 'pago', 'match' => true],
        ['text' => '.', 'match' => false],
    ]);
});

test('catastrophic patterns give up instead of hanging', function () {
    $started = microtime(true);

    expect(MailPattern::compile('(a+)+$')->excerpt(str_repeat('a', 5000).'b'))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(2);
});

test('address lists with quoted names are parsed', function () {
    $addresses = MailAddress::parseList('"Ruiz, Marta" <marta@example.com>, Pablo Gil <pablo@example.com>, lucia@example.com');

    expect(array_map(fn (MailAddress $address) => $address->toArray(), $addresses))->toBe([
        ['name' => 'Ruiz, Marta', 'email' => 'marta@example.com'],
        ['name' => 'Pablo Gil', 'email' => 'pablo@example.com'],
        ['name' => null, 'email' => 'lucia@example.com'],
    ]);
});

test('html emails become readable text without styles or scripts', function () {
    $html = '<html><head><style>p{color:red}</style></head><body><p>Hola&nbsp;John,</p><p>Mira <a href="https://x.test">esto</a>:<br>uno</p><ul><li>dos</li></ul><script>alert(1)</script></body></html>';

    expect((new HtmlToText)->convert($html))->toBe("Hola John,\n\nMira esto:\nuno\n\n• dos");
});
