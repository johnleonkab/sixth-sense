<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Comms\Ingestion\FakeInboundFeed;
use App\Comms\Ingestion\GmailInbound;
use App\Comms\Ingestion\InboundFeed;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Ingestion\ReadOnlySlackClient;
use App\Comms\Ingestion\SlackInbound;
use App\Comms\Ingestion\WriteAttemptedFromIngestion;
use App\Comms\Messages\Party;
use App\Connections\Provider;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsScope;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

test('slack and gmail streams replay to one message per source id and one shared person', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $ingestor = app(Ingestor::class);
    $marta = new Party('U-marta', 'marta@example.com', 'Marta', false, 'marta');
    $me = new Party('U-me', 'me@example.com', 'Me');
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');

    $original = new SlackInbound('T1', 'D1', '100.1', 'dm', $marta, [$me], 'Can you review this?', $at, '100.1');
    $ingestor->ingest($user, $original);
    $ingestor->ingest($user, $original);
    $ingestor->ingest($user, new SlackInbound(
        'T1', 'D1', '100.1', 'dm', $marta, [$me], 'Can you review this today?', $at, '100.1',
        subtype: 'message_changed',
        revision: '100.2',
    ));
    $ingestor->ingest($user, new SlackInbound(
        'T1', 'D1', '100.9', 'dm', $marta, [], 'Forget that', $at->addHour(),
        subtype: 'message_deleted',
    ));
    $ingestor->ingest($user, new GmailInbound(
        'g-1',
        't-1',
        new Party('marta@example.com', 'marta@example.com', 'Marta Ruiz'),
        [new Party('me@example.com', 'me@example.com', 'Me')],
        'Prepaid flow',
        'Before Friday.',
        $at->addHours(2),
        ['INBOX'],
    ));
    $ingestor->ingest($user, new SlackInbound(
        'T1', 'D1', '200.1', 'dm', $me, [$marta], 'On it.', $at->addHours(3),
        mine: true,
    ));

    $martaPerson = CommsPerson::query()->where('display_name', 'Marta')->first();
    $edited = CommsMessage::query()->where('external_id', 'T1:D1:100.1')->first();
    $deleted = CommsMessage::query()->where('external_id', 'T1:D1:100.9')->first();

    expect(CommsMessage::query()->count())->toBe(4)
        ->and(CommsPerson::query()->count())->toBe(2)
        ->and($martaPerson)->not->toBeNull()
        ->and($martaPerson->identities()->count())->toBe(2)
        ->and($edited->text)->toBe('Can you review this today?')
        ->and($edited->deleted_at)->toBeNull()
        ->and($edited->thread_ref)->toBe('100.1')
        ->and($deleted->deleted_at)->not->toBeNull()
        ->and(CommsMessage::query()->where('external_id', 'g-1')->value('thread_ref'))->toBe('t-1')
        ->and(CommsMessage::query()->where('external_id', 'T1:D1:200.1')->value('direction'))->toBe('out')
        ->and(CommsEvent::query()->where('external_id', 'T1:D1:200.1')->value('type'))->toBe(EventType::MessageSent)
        ->and(CommsEvent::query()->where('external_id', 'T1:D1:100.1')->where('type', EventType::MessageReceived)->count())->toBe(1)
        ->and(CommsEvent::query()->whereNull('processed_at')->count())->toBe(0);

    $messages = CommsMessage::query()->count();
    $people = CommsPerson::query()->count();

    app(Replay::class)->run($user);
    app(Replay::class)->run($user);

    expect(CommsMessage::query()->count())->toBe($messages)
        ->and(CommsPerson::query()->count())->toBe($people)
        ->and(CommsMessage::query()->where('external_id', 'T1:D1:100.1')->value('text'))->toBe('Can you review this today?')
        ->and(CommsPerson::query()->where('display_name', 'Marta')->first()->identities()->count())->toBe(2);
});

test('a message in a channel outside scope is not appended', function () {
    $user = User::factory()->create();

    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'C-random', '1.1', 'channel', new Party('U9', 'a@example.com', 'Ana'), [], 'Hello', CarbonImmutable::parse('2026-11-02T09:00:00Z'),
    ));

    expect(CommsEvent::query()->count())->toBe(0)
        ->and(CommsMessage::query()->count())->toBe(0);
});

test('a channel on the allow list is appended', function () {
    $user = User::factory()->create();
    CommsScope::factory()->create([
        'user_id' => $user->id,
        'slack_channel_ids' => ['C-allowed'],
    ]);

    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'C-allowed', '1.1', 'channel', new Party('U9', 'a@example.com', 'Ana'), [], 'Hello', CarbonImmutable::parse('2026-11-02T09:00:00Z'),
    ));

    expect(CommsMessage::query()->count())->toBe(1);
});

test('a channel join is not appended', function () {
    $user = User::factory()->create();

    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'C1', '1.1', 'channel', new Party('U9', null, 'Ana'), [], 'joined', CarbonImmutable::parse('2026-11-02T09:00:00Z'),
        subtype: 'channel_join',
        channelAllowed: true,
    ));

    expect(CommsMessage::query()->count())->toBe(0);
});

test('bot messages are kept in direct messages and dropped in channels', function () {
    $user = User::factory()->create();
    $bot = new Party('B1', null, 'Standup', true);
    $at = CarbonImmutable::parse('2026-11-02T09:00:00Z');

    app(Ingestor::class)->ingest($user, new SlackInbound('T1', 'D1', '1.1', 'dm', $bot, [], 'Reminder', $at));
    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'C1', '2.1', 'channel', $bot, [], 'Reminder', $at,
        mentionsUser: true,
    ));

    $stored = CommsMessage::query()->get();

    expect($stored)->toHaveCount(1)
        ->and($stored->first()->flags['automated'])->toBeTrue();
});

test('two people with the same display name stay two people', function () {
    $user = User::factory()->create();
    $at = CarbonImmutable::parse('2026-11-02T09:00:00Z');

    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'D1', '1.1', 'dm', new Party('U1', 'one@example.com', 'Ana'), [], 'One', $at,
    ));
    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'D2', '2.1', 'dm', new Party('U2', 'two@example.com', 'Ana'), [], 'Two', $at,
    ));

    expect(CommsPerson::query()->where('display_name', 'Ana')->count())->toBe(2);
});

test('connected accounts become one self person', function () {
    $user = User::factory()->create();
    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Google,
        'email' => 'me@example.com',
        'name' => 'Me',
    ]);
    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'name' => 'Me',
        'metadata' => ['user_id' => 'U-me', 'team_id' => 'T1'],
    ]);

    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'D1', '1.1', 'dm', new Party('U-other', 'other@example.com', 'Other'), [], 'Hi', CarbonImmutable::parse('2026-11-02T09:00:00Z'),
    ));

    $self = CommsPerson::query()->where('relation', 'self')->get();

    expect($self)->toHaveCount(1)
        ->and($self->first()->identities()->count())->toBe(2)
        ->and(CommsPerson::query()->count())->toBe(2);
});

test('a channel stays in scope after the user posts in it', function () {
    $user = User::factory()->create();
    $at = CarbonImmutable::now()->subDay();

    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'C9', '1.1', 'channel', new Party('U-me', 'me@example.com', 'Me'), [], 'My note', $at,
        mine: true,
    ));
    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'C9', '1.2', 'channel', new Party('U9', 'a@example.com', 'Ana'), [], 'Reply', $at->addMinute(),
    ));
    app(Ingestor::class)->ingest($user, new SlackInbound(
        'T1', 'C-other', '1.3', 'channel', new Party('U9', 'a@example.com', 'Ana'), [], 'Elsewhere', $at->addMinutes(2),
    ));

    expect(CommsMessage::query()->pluck('channel_ref')->all())->toBe(['C9', 'C9']);
});

test('gmail drops promotions, social, spam, trash and drafts', function () {
    $user = User::factory()->create();
    $at = CarbonImmutable::parse('2026-11-02T09:00:00Z');
    $from = new Party('news@example.com', 'news@example.com', 'News');

    foreach (['CATEGORY_PROMOTIONS', 'CATEGORY_SOCIAL', 'SPAM', 'TRASH', 'DRAFT'] as $label) {
        app(Ingestor::class)->ingest($user, new GmailInbound(
            'g-'.$label, 't-'.$label, $from, [], 'Offer', 'Buy', $at, ['INBOX', $label],
        ));
    }

    app(Ingestor::class)->ingest($user, new GmailInbound(
        'g-ok', 't-ok', $from, [], 'Question', 'Can you look?', $at, ['INBOX'],
    ));
    app(Ingestor::class)->ingest($user, new GmailInbound(
        'g-sent', 't-sent', new Party('me@example.com', 'me@example.com', 'Me'), [$from], 'Reply', 'Done', $at, ['SENT'],
        mine: true,
    ));

    expect(CommsMessage::query()->orderBy('external_id')->pluck('external_id')->all())->toBe(['g-ok', 'g-sent'])
        ->and(CommsMessage::query()->where('external_id', 'g-sent')->value('direction'))->toBe('out');
});

test('backfill appends in time order and does nothing the second time', function () {
    $user = User::factory()->create();
    $feed = new FakeInboundFeed;
    $feed->items = [
        new SlackInbound('T1', 'D1', '2.1', 'dm', new Party('U1', 'a@example.com', 'Ana'), [], 'Later', CarbonImmutable::now()->subDay()),
        new SlackInbound('T1', 'D1', '1.1', 'dm', new Party('U1', 'a@example.com', 'Ana'), [], 'Earlier', CarbonImmutable::now()->subDays(3)),
        new SlackInbound('T1', 'D1', '9.1', 'dm', new Party('U1', 'a@example.com', 'Ana'), [], 'Old', CarbonImmutable::now()->subDays(40)),
    ];
    $this->app->instance(InboundFeed::class, $feed);

    $this->artisan('comms:backfill', ['user' => $user->id, '--days' => 14])->assertSuccessful();

    $messages = CommsEvent::query()
        ->whereIn('type', [EventType::MessageReceived, EventType::MessageSent])
        ->orderBy('id')
        ->get();
    $count = CommsEvent::query()->count();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->normalized['text'])->toBe('Earlier')
        ->and($messages[0]->normalized['backfill'])->toBeTrue()
        ->and($messages[1]->normalized['text'])->toBe('Later');

    $this->artisan('comms:backfill', ['user' => $user->id, '--days' => 14])->assertSuccessful();

    expect(CommsEvent::query()->count())->toBe($count);
});

test('backfill reports a missing user', function () {
    $this->artisan('comms:backfill', ['user' => 999999])->assertFailed();
});

test('reconciliation keeps the alert cursor and stores its own', function () {
    $user = User::factory()->create();
    $previousCursor = now()->subHour()->toIso8601String();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'watch_cursor' => 'keep-me',
        'metadata' => [
            'team_id' => 'T1',
            'user_id' => 'U-me',
            'comms_cursor' => $previousCursor,
        ],
    ]);
    $feed = new FakeInboundFeed;
    $feed->items = [
        new SlackInbound('T1', 'D1', '1.1', 'dm', new Party('U1', 'a@example.com', 'Ana'), [], 'Hello', CarbonImmutable::now()->subMinutes(5)),
    ];
    $this->app->instance(InboundFeed::class, $feed);

    $this->artisan('comms:reconcile', ['user' => $user->id])->assertSuccessful();

    $account->refresh();

    expect($account->watch_cursor)->toBe('keep-me')
        ->and($account->metadata['comms_cursor'])->not->toBe($previousCursor)
        ->and(CommsMessage::query()->count())->toBe(1);
});

test('a write method on the read only slack client is refused', function () {
    Http::preventStrayRequests();

    $client = app(ReadOnlySlackClient::class);
    $account = ConnectedAccount::factory()->create();

    expect(fn () => $client->call($account, 'chat.postMessage'))->toThrow(WriteAttemptedFromIngestion::class)
        ->and(fn () => $client->call($account, 'conversations.mark'))->toThrow(WriteAttemptedFromIngestion::class);
});

test('ingestion does not depend on a write path', function () {
    $forbidden = ['Outbox', 'Comms\\Gate', 'chat.postMessage', 'conversations.mark', 'messages.modify', 'messages.send'];

    foreach (File::allFiles(app_path('Comms/Ingestion')) as $file) {
        $source = $file->getContents();

        foreach ($forbidden as $needle) {
            expect($source)->not->toContain($needle);
        }
    }
});
