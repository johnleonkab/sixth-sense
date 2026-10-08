<?php

use App\Connections\Capability;
use App\Connectors\Google\GmailMailbox;
use App\Connectors\Mail\MailPattern;
use App\Connectors\Mail\MailSearch;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function gmailMessage(string $id, array $overrides = []): array
{
    $body = $overrides['body'] ?? 'Hola John, ¿puedes revisar el flujo de prepago antes de las 12?';
    $html = $overrides['html'] ?? null;

    return [
        'id' => $id,
        'threadId' => "t-{$id}",
        'labelIds' => $overrides['labels'] ?? ['INBOX', 'UNREAD'],
        'snippet' => $overrides['snippet'] ?? 'Hola John, ¿puedes revisar el flujo de prepago&hellip;',
        'internalDate' => (string) (($overrides['date'] ?? CarbonImmutable::parse('2026-10-07 08:47:00', 'UTC'))->getTimestamp() * 1000),
        'payload' => [
            'mimeType' => 'multipart/mixed',
            'headers' => [
                ['name' => 'From', 'value' => $overrides['from'] ?? 'Marta Ruiz <marta@example.com>'],
                ['name' => 'To', 'value' => 'John León <jhon@work.com>'],
                ['name' => 'Subject', 'value' => $overrides['subject'] ?? 'Flujo de prepago v4'],
            ],
            'parts' => array_values(array_filter([
                $html === null ? ['mimeType' => 'text/plain', 'filename' => '', 'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')]] : null,
                $html !== null ? ['mimeType' => 'text/html', 'filename' => '', 'body' => ['data' => rtrim(strtr(base64_encode($html), '+/', '-_'), '=')]] : null,
                ['mimeType' => 'application/pdf', 'filename' => 'prepago.pdf', 'body' => ['attachmentId' => 'att', 'size' => 2048]],
            ])),
        ],
    ];
}

/**
 * @param  array<string, list<array<string, mixed>>|int>  $messagesByToken
 */
function fakeGmail(array $messagesByToken): void
{
    Http::fake(function (Request $request) use ($messagesByToken) {
        $token = str($request->header('Authorization')[0] ?? '')->after('Bearer ')->toString();
        $messages = $messagesByToken[$token] ?? [];

        if (is_int($messages)) {
            return Http::response([], $messages);
        }

        if (preg_match('~/messages/([^/?]+)~', $request->url(), $match)) {
            $message = collect($messages)->firstWhere('id', $match[1]);

            return $message ? Http::response($message) : Http::response([], 404);
        }

        return Http::response(['messages' => array_map(fn (array $message) => ['id' => $message['id']], $messages)]);
    });
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->work = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@work.com', 'access_token' => 'work-token']);
});

test('it turns the search into a gmail query', function () {
    fakeGmail([]);

    app(GmailMailbox::class)->search($this->user, new MailSearch(
        from: 'Marta Ruiz',
        subject: 'prepago',
        text: '"paso 3"',
        after: CarbonImmutable::parse('2026-10-01'),
        before: CarbonImmutable::parse('2026-10-07'),
        hasAttachment: true,
        folder: 'inbox',
    ));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://gmail.googleapis.com/gmail/v1/users/me/messages?')
        && $request['q'] === 'from:"Marta Ruiz" subject:(prepago) "paso 3" after:2026/10/01 before:2026/10/08 has:attachment in:inbox'
        && (int) $request['maxResults'] === 10);
});

test('without a pattern it only fetches metadata', function () {
    fakeGmail(['work-token' => [gmailMessage('m1')]]);

    $result = app(GmailMailbox::class)->search($this->user, new MailSearch(from: 'Marta'));
    $message = $result->messages[0]->message;

    expect($message->ref)->toBe("{$this->work->id}.m1")
        ->and($message->from->name)->toBe('Marta Ruiz')
        ->and($message->subject)->toBe('Flujo de prepago v4')
        ->and($message->snippet)->toBe('Hola John, ¿puedes revisar el flujo de prepago…')
        ->and($message->unread)->toBeTrue()
        ->and($result->messages[0]->excerpt)->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/messages/m1?format=metadata&metadataHeaders=From'));
});

test('a pattern is matched on the full body and filters the candidates', function () {
    fakeGmail(['work-token' => [
        gmailMessage('m1', ['body' => 'Te paso las notas de la daily.', 'subject' => 'Notas']),
        gmailMessage('m2', ['subject' => 'Cambios', 'body' => 'El cambio grande: el importe total antes de elegir mesa en el pre-pago.']),
    ]]);

    $result = app(GmailMailbox::class)->search($this->user, new MailSearch(from: 'Marta', pattern: MailPattern::compile('pre-?pago')));

    expect($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->message->ref)->toEndWith('.m2')
        ->and($result->messages[0]->excerpt['match'])->toBe('pre-pago')
        ->and($result->scanned)->toBe(2);

    Http::assertSent(fn (Request $request) => (int) ($request['maxResults'] ?? 0) === 40);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/messages/m2?format=full'));
});

test('html-only emails are read as text', function () {
    fakeGmail(['work-token' => [gmailMessage('m1', ['html' => '<p>Firmamos el <b>contrato</b> el viernes.</p>'])]]);

    $message = app(GmailMailbox::class)->message($this->user, "{$this->work->id}.m1");

    expect($message->body)->toBe('Firmamos el contrato el viernes.')
        ->and($message->attachments)->toBe([['name' => 'prepago.pdf', 'mime_type' => 'application/pdf', 'size' => 2048, 'id' => 'att']])
        ->and($message->url)->toBe('https://mail.google.com/mail/?authuser=jhon%40work.com#all/m1');
});

test('results from every account are merged newest first', function () {
    ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@gmail.com', 'access_token' => 'personal-token']);
    fakeGmail([
        'work-token' => [gmailMessage('old', ['date' => CarbonImmutable::parse('2026-10-01 10:00:00')])],
        'personal-token' => [gmailMessage('new', ['date' => CarbonImmutable::parse('2026-10-06 10:00:00')])],
    ]);

    $result = app(GmailMailbox::class)->search($this->user, new MailSearch(text: 'prepago'));

    expect(array_map(fn ($found) => $found->message->account, $result->messages))->toBe(['jhon@gmail.com', 'jhon@work.com']);
});

test('accounts that fail are reported and the rest still answer', function () {
    ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@gmail.com', 'access_token' => 'personal-token']);
    fakeGmail(['work-token' => [gmailMessage('m1')], 'personal-token' => 500]);

    $result = app(GmailMailbox::class)->search($this->user, new MailSearch(text: 'prepago'));

    expect($result->messages)->toHaveCount(1)
        ->and($result->failedAccounts)->toBe(['jhon@gmail.com']);
});

test('accounts with email switched off are not searched', function () {
    $this->work->toggleCapability(Capability::GmailRead, false);
    fakeGmail([]);

    expect(app(GmailMailbox::class)->search($this->user, new MailSearch(text: 'x'))->accounts)->toBe([]);
    Http::assertNothingSent();
});

test('a message of someone else account can not be opened', function () {
    $someoneElse = ConnectedAccount::factory()->create(['access_token' => 'work-token']);
    fakeGmail(['work-token' => [gmailMessage('m1')]]);

    expect(app(GmailMailbox::class)->message($this->user, "{$someoneElse->id}.m1"))->toBeNull();
    Http::assertNothingSent();
});

test('an attachment is downloaded with the id gmail gives on a fresh read', function () {
    $pdf = "%PDF-1.4\nbytes";
    Http::fake(function (Request $request) use ($pdf) {
        return str_contains($request->url(), '/attachments/')
            ? Http::response(['data' => rtrim(strtr(base64_encode($pdf), '+/', '-_'), '='), 'size' => strlen($pdf)])
            : Http::response(gmailMessage('m1'));
    });

    $mailbox = app(GmailMailbox::class);

    expect($mailbox->attachment($this->user, "{$this->work->id}.m1", 0))->toBe($pdf)
        ->and($mailbox->attachment($this->user, "{$this->work->id}.m1", 1))->toBeNull()
        ->and($mailbox->attachment($this->user, '999.m1', 0))->toBeNull();

    Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/messages/m1/attachments/att'));
});

test('a message counts as answered once the user sent something later in its thread', function (array $thread, bool $answered) {
    Http::fake([
        '*/messages/m1*' => Http::response(['id' => 'm1', 'threadId' => 't1', 'internalDate' => '1000']),
        '*/threads/t1*' => Http::response(['id' => 't1', 'messages' => $thread]),
    ]);

    expect(app(GmailMailbox::class)->repliedAfter($this->user, "{$this->work->id}.m1"))->toBe($answered);
})->with([
    'replied' => [[['id' => 'm1', 'labelIds' => ['INBOX'], 'internalDate' => '1000'], ['id' => 'm2', 'labelIds' => ['SENT'], 'internalDate' => '2000']], true],
    'only earlier messages of the user' => [[['id' => 'm0', 'labelIds' => ['SENT'], 'internalDate' => '500'], ['id' => 'm1', 'labelIds' => ['INBOX'], 'internalDate' => '1000']], false],
    'others replied' => [[['id' => 'm1', 'labelIds' => ['INBOX'], 'internalDate' => '1000'], ['id' => 'm3', 'labelIds' => ['INBOX'], 'internalDate' => '3000']], false],
]);
