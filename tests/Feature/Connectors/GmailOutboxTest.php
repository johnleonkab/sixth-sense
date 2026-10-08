<?php

use App\Connections\Capability;
use App\Connectors\Google\GmailOutbox;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\OutgoingEmail;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function decodedRaw(Request $request): string
{
    return (string) base64_decode(strtr($request['raw'], '-_', '+/'));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@work.com', 'access_token' => 'tok']);
});

test('a reply is sent as mime in its thread with the threading headers', function () {
    Http::fake(['*/messages/send' => Http::response(['id' => 'sent1', 'threadId' => 'thread-1'])]);

    $ref = app(GmailOutbox::class)->send($this->user, new OutgoingEmail(
        mode: 'reply', accountId: $this->account->id, from: 'jhon@work.com',
        to: [new MailAddress('Andrés Vela', 'andres@grupovela.com')], cc: [new MailAddress(null, 'pablo@work.com')],
        subject: 'Re: Contrato', body: 'Lo firmo mañana.', original: "{$this->account->id}.m1",
        threadId: 'thread-1', inReplyTo: '<abc@grupovela.com>', references: '<root@grupovela.com>',
    ));

    expect($ref)->toBe("{$this->account->id}.sent1");

    Http::assertSent(function (Request $request) {
        $mime = decodedRaw($request);

        return $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
            && $request['threadId'] === 'thread-1'
            && str_contains($mime, 'From: jhon@work.com')
            && str_contains($mime, 'To: =?utf-8?Q?Andr=C3=A9s?= Vela <andres@grupovela.com>')
            && str_contains($mime, 'Cc: pablo@work.com')
            && str_contains($mime, 'Subject: Re: Contrato')
            && str_contains($mime, 'In-Reply-To: <abc@grupovela.com>')
            && str_contains($mime, 'References: <root@grupovela.com> <abc@grupovela.com>')
            && (str_contains($mime, 'Lo firmo mañana.') || str_contains($mime, 'Lo firmo ma=C3=B1ana.'));
    });
});

test('a forward attaches the original files', function () {
    Http::fake([
        '*/attachments/att1' => Http::response(['data' => rtrim(strtr(base64_encode('%PDF-1.4 contrato'), '+/', '-_'), '='), 'size' => 17]),
        '*/messages/send' => Http::response(['id' => 'sent2']),
    ]);

    app(GmailOutbox::class)->send($this->user, new OutgoingEmail(
        mode: 'forward', accountId: $this->account->id, from: 'jhon@work.com',
        to: [new MailAddress(null, 'lucia@work.com')], cc: [], subject: 'Fwd: Contrato', body: 'Mira esto.',
        original: "{$this->account->id}.m1",
        attachments: [['name' => 'contrato.pdf', 'mime_type' => 'application/pdf', 'size' => 17, 'id' => 'att1']],
    ));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/messages/m1/attachments/att1'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/messages/send')
        && str_contains(decodedRaw($request), 'filename=contrato.pdf')
        && str_contains(decodedRaw($request), base64_encode('%PDF-1.4 contrato')));
});

test('it refuses to send from an account without the send permission', function () {
    $this->account->toggleCapability(Capability::GmailSend, false);
    Http::fake();

    expect(fn () => app(GmailOutbox::class)->send($this->user, new OutgoingEmail('new', $this->account->id, 'jhon@work.com', [new MailAddress(null, 'x@y.z')], [], 'Hola', 'Hola')))
        ->toThrow(RuntimeException::class, 'no tiene permiso para enviar');

    Http::assertNothingSent();
    expect(app(GmailOutbox::class)->senders($this->user))->toBe([]);
});

test('replies go out from the account that received the email', function () {
    expect(app(GmailOutbox::class)->senderFor($this->user, "{$this->account->id}.m1")->email)->toBe('jhon@work.com')
        ->and(app(GmailOutbox::class)->senderFor($this->user, '999.m1'))->toBeNull();
});
