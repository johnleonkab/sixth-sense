<?php

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Connections\Provider;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(CompletesTasks::class, new FakeCompleter);
});

test('jira notification emails are noise when jira is already watched', function () {
    $user = noiseUser(withJira: true);
    $at = CarbonImmutable::parse('2026-11-11T08:55:59Z');

    noiseMail(
        $user,
        'jira-1',
        new Party('jira-david', 'jira@covermanager.atlassian.net', 'David Montiel (Jira)'),
        "[JIRA] Dmytro Bezsmertnyi mentioned you on CPD-7230\n\nThanks @jleon\nhttps://covermanager.atlassian.net/browse/CPD-7230\natl-paas.net",
        $at,
        subject: '[JIRA] Dmytro Bezsmertnyi mentioned you on CPD-7230',
    );

    expect(CommsIntent::query()->count())->toBe(0)
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('flags')['noise'])->toBeTrue()
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('flags')['label'])->toBe('noise')
        ->and(CommsEvent::query()->where('type', EventType::MessageFlagged)->value('normalized')['label'])->toBe('noise');
});

test('jira mail by display name is noise even without an atlassian address', function () {
    $user = noiseUser(withJira: true);

    noiseMail(
        $user,
        'jira-2',
        new Party('david@cm.com', 'david@cm.com', 'David Montiel (Jira)'),
        "Gregory mentioned you on CPD-7522\nPlease take a look",
        CarbonImmutable::parse('2026-11-11T22:52:00Z'),
        subject: '[JIRA] Gregory Chasovskykh mentioned you on CPD-7522',
    );

    expect(CommsIntent::query()->count())->toBe(0)
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('flags')['noise'])->toBeTrue();
});

test('jira assigned-to-you mail is noise when jira is already watched', function () {
    $user = noiseUser(withJira: true);
    $fake = new FakeCompleter;
    app()->instance(CompletesTasks::class, $fake);

    noiseMail(
        $user,
        'jira-assign-watched',
        new Party('jira@covermanager.atlassian.net', 'jira@covermanager.atlassian.net', 'Jira'),
        "CPD-1 assigned to you\nPlease look",
        CarbonImmutable::parse('2026-11-11T10:00:00Z'),
        subject: 'CPD-1 assigned to you',
    );

    expect(CommsIntent::query()->count())->toBe(0)
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('flags')['noise'])->toBeTrue()
        ->and($fake->calls)->toBe([]);
});

test('jira assigned-to-you mail stays an ask when jira is not connected', function () {
    $user = noiseUser(withJira: false);
    $fake = new FakeCompleter;
    $fake->script = [
        new ModelCompletion([
            'operations' => [[
                'op' => 'open',
                'what' => 'Revisar CPD-1',
                'quote' => 'assigned to you',
                'confidence' => 0.9,
                'requesters' => ['gmail:jira@covermanager.atlassian.net'],
                'responders' => ['self'],
            ]],
            'confidence' => 0.9,
        ], 'scripted'),
    ];
    app()->instance(CompletesTasks::class, $fake);

    // inbox_rules labels this ask before the triage model, so no triage call.
    noiseMail(
        $user,
        'jira-assign',
        new Party('jira@covermanager.atlassian.net', 'jira@covermanager.atlassian.net', 'Jira'),
        "CPD-1 assigned to you\nPlease look",
        CarbonImmutable::parse('2026-11-11T10:00:00Z'),
        subject: 'CPD-1 assigned to you',
    );

    expect(CommsMessage::query()->whereBelongsTo($user)->value('flags')['label'])->toBe('ask')
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('flags')['noise'])->toBeFalse()
        ->and(CommsIntent::query()->count())->toBe(1);
});

test('personio reminders are noise without calling the triage model', function () {
    $user = noiseUser(withJira: false);
    $fake = new FakeCompleter;
    app()->instance(CompletesTasks::class, $fake);

    noiseMail(
        $user,
        'personio-1',
        new Party('info@m.personio.com', 'info@m.personio.com', 'Personio Team'),
        "Recordatorio para firmar el documento salary.pdf\nPersonio. El sistema operativo de recursos humanos.",
        CarbonImmutable::parse('2026-11-11T05:07:15Z'),
        subject: 'Recordatorio para firmar el documento salary.pdf',
    );

    expect(CommsIntent::query()->count())->toBe(0)
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('flags')['noise'])->toBeTrue()
        ->and($fake->calls)->toBe([]);
});

function noiseUser(bool $withJira): User
{
    $user = User::factory()->create();

    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Google,
        'email' => 'me@cm.com',
        'scopes' => Provider::Google->scopes(),
    ]);

    if ($withJira) {
        ConnectedAccount::factory()->for($user)->create([
            'provider' => Provider::Atlassian,
            'email' => 'me@cm.com',
            'scopes' => Provider::Atlassian->scopes(),
            'metadata' => ['cloud_id' => 'cloud-1', 'site_url' => 'https://covermanager.atlassian.net'],
        ]);
    }

    app(IdentityResolver::class)->ensureSelf($user);

    return $user;
}

function noiseMail(
    User $user,
    string $id,
    Party $from,
    string $text,
    CarbonImmutable $at,
    string $subject,
): void {
    $message = new Message(
        externalId: $id,
        source: Source::Gmail,
        channel: 'INBOX',
        thread: 'thread-'.$id,
        author: $from,
        recipients: [new Party('me@cm.com', 'me@cm.com', 'Me')],
        direction: Direction::Incoming,
        at: $at,
        text: $subject."\n\n".$text,
    );

    app(EventLog::class)->append(
        user: $user,
        type: EventType::MessageReceived,
        source: Source::Gmail,
        externalId: $id,
        payload: null,
        normalized: array_merge($message->toArray(), ['subject' => $subject]),
        causation: null,
        occurredAt: $at,
    );
}
