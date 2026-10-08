<?php

use App\Connectors\Mail\EmailComposer;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailSender;
use Carbon\CarbonImmutable;

function original(array $overrides = []): MailMessage
{
    return new MailMessage(...[
        'ref' => '7.m1',
        'account' => 'jhon@work.com',
        'from' => new MailAddress('Andrés Vela', 'andres@grupovela.com'),
        'to' => [new MailAddress('John León', 'jhon@work.com'), new MailAddress('Pablo Gil', 'pablo@work.com')],
        'cc' => [new MailAddress('Marta Ruiz', 'marta@work.com'), new MailAddress(null, 'JHON@work.com')],
        'subject' => 'Contrato Grupo Vela',
        'date' => CarbonImmutable::parse('2026-10-07 17:05'),
        'snippet' => '',
        'body' => "Necesitamos la firma antes del viernes.\nGracias.",
        'attachments' => [['name' => 'contrato.pdf', 'mime_type' => 'application/pdf', 'size' => 1000, 'id' => 'att1']],
        'threadId' => 'thread-1',
        'messageId' => '<abc@grupovela.com>',
        'references' => '<root@grupovela.com>',
        ...$overrides,
    ]);
}

function sender(): MailSender
{
    return new MailSender(7, 'jhon@work.com');
}

test('a reply goes to the sender, in the same thread, quoting the original', function () {
    $email = (new EmailComposer)->reply(original(), sender(), 'Lo firmo mañana.', all: false, ownAddresses: ['jhon@work.com']);

    expect($email->mode)->toBe('reply')
        ->and(array_column(array_map(fn ($a) => $a->toArray(), $email->to), 'email'))->toBe(['andres@grupovela.com'])
        ->and($email->cc)->toBe([])
        ->and($email->subject)->toBe('Re: Contrato Grupo Vela')
        ->and($email->threadId)->toBe('thread-1')
        ->and($email->inReplyTo)->toBe('<abc@grupovela.com>')
        ->and($email->references)->toBe('<root@grupovela.com>')
        ->and($email->body)->toStartWith("Lo firmo mañana.\n\nEl 7 de octubre de 2026, 17:05, Andrés Vela <andres@grupovela.com> escribió:\n> Necesitamos la firma antes del viernes.\n> Gracias.");
});

test('reply all copies everyone else, never the user, without duplicates', function () {
    $email = (new EmailComposer)->reply(original(), sender(), 'Ok.', all: true, ownAddresses: ['jhon@work.com']);

    expect(array_map(fn ($a) => $a->email, $email->to))->toBe(['andres@grupovela.com'])
        ->and(array_map(fn ($a) => $a->email, $email->cc))->toBe(['pablo@work.com', 'marta@work.com']);
});

test('replying to an email the user sent goes back to its recipients', function () {
    $mine = original(['from' => new MailAddress('John León', 'jhon@work.com'), 'cc' => []]);

    $email = (new EmailComposer)->reply($mine, sender(), 'Recordatorio.', all: false, ownAddresses: ['jhon@work.com']);

    expect(array_map(fn ($a) => $a->email, $email->to))->toBe(['pablo@work.com']);
});

test('reply-to is respected', function () {
    $email = (new EmailComposer)->reply(original(['replyTo' => [new MailAddress('Legal', 'legal@grupovela.com')]]), sender(), 'Ok.', all: false, ownAddresses: []);

    expect(array_map(fn ($a) => $a->email, $email->to))->toBe(['legal@grupovela.com']);
});

test('subjects are not prefixed twice', function () {
    $email = (new EmailComposer)->reply(original(['subject' => 'RE: Contrato']), sender(), 'Ok.', all: false, ownAddresses: []);

    expect($email->subject)->toBe('RE: Contrato');
});

test('a forward carries the original with its attachments below the note', function () {
    $email = (new EmailComposer)->forward(original(), sender(), [new MailAddress('Lucía', 'lucia@work.com')], 'Lucía, échale un ojo.');

    expect($email->mode)->toBe('forward')
        ->and($email->subject)->toBe('Fwd: Contrato Grupo Vela')
        ->and($email->threadId)->toBeNull()
        ->and($email->attachments[0]['id'])->toBe('att1')
        ->and($email->body)->toStartWith("Lucía, échale un ojo.\n\n---------- Mensaje reenviado ----------\nDe: Andrés Vela <andres@grupovela.com>")
        ->and($email->body)->toContain("Asunto: Contrato Grupo Vela\nPara: John León <jhon@work.com>, Pablo Gil <pablo@work.com>\nCC: Marta Ruiz <marta@work.com>, <JHON@work.com>\n\nNecesitamos la firma");
});
