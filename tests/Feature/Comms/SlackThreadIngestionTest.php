<?php

use App\Alerts\AlertInbox;
use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Comms\Cutover\CandidateHandoff;
use App\Comms\Events\Source;
use App\Comms\Ingestion\SlackHistory;
use App\Connections\Provider;
use App\Connectors\Mail\MailAddress;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

test('slack history pulls thread replies including the users own answer', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U-me'],
        'scopes' => Provider::Slack->scopes(),
    ]);
    $author = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'source' => Source::Slack,
        'channel_ref' => 'C1',
        'thread_ref' => '100.1',
        'external_id' => 'T1:C1:100.1',
        'text' => '¿Me revisas el PR?',
    ]);

    Http::fake(function (Request $request) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();

        return Http::response(match ($method) {
            'conversations.info' => ['ok' => true, 'channel' => ['id' => 'C1', 'name' => 'producto']],
            'conversations.history' => ['ok' => true, 'messages' => [[
                'ts' => '100.1',
                'user' => 'U-marta',
                'text' => '¿Me revisas el PR?',
                'reply_count' => 1,
                'latest_reply' => '100.2',
                'thread_ts' => '100.1',
            ]]],
            'conversations.replies' => ['ok' => true, 'messages' => [
                ['ts' => '100.1', 'user' => 'U-marta', 'text' => '¿Me revisas el PR?', 'thread_ts' => '100.1'],
                ['ts' => '100.2', 'user' => 'U-me', 'text' => 'Lo miro ahora', 'thread_ts' => '100.1'],
            ]],
            'users.info' => ['ok' => true, 'user' => [
                'id' => $request['user'],
                'name' => $request['user'] === 'U-me' ? 'me' : 'marta',
                'real_name' => $request['user'] === 'U-me' ? 'Me' : 'Marta',
                'profile' => ['email' => $request['user'] === 'U-me' ? 'me@example.com' : 'marta@example.com'],
            ]],
            default => ['ok' => false, 'error' => 'unknown_method'],
        });
    });

    $items = app(SlackHistory::class)->collect($account, CarbonImmutable::createFromTimestamp(100));

    expect($items)->toHaveCount(2)
        ->and(collect($items)->firstWhere(fn ($item) => $item->ts === '100.2')?->mine)->toBeTrue()
        ->and(collect($items)->firstWhere(fn ($item) => $item->ts === '100.2')?->text)->toBe('Lo miro ahora')
        ->and(collect($items)->firstWhere(fn ($item) => $item->ts === '100.2')?->threadTs)->toBe('100.1');
});

test('slack history also resumes open intent threads that fell out of recent history', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U-me'],
        'scopes' => Provider::Slack->scopes(),
    ]);
    $author = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    $intent = CommsIntent::factory()->for($user)->create(['state' => 'open', 'key' => 'evt:1:0']);
    $root = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'source' => Source::Slack,
        'channel_ref' => 'C9',
        'thread_ref' => '200.1',
        'external_id' => 'T1:C9:200.1',
        'text' => 'Necesito tu ok',
        'occurred_at' => now()->subDay(),
    ]);
    CommsConversationLink::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $root->id,
        'operation' => 'open',
        'confidence' => 0.9,
        'uncertain' => false,
    ]);

    Http::fake(function (Request $request) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();

        return Http::response(match ($method) {
            'conversations.info' => ['ok' => true, 'channel' => ['id' => 'C9', 'name' => 'squad']],
            'conversations.history' => ['ok' => true, 'messages' => []],
            'conversations.replies' => ['ok' => true, 'messages' => [
                ['ts' => '200.1', 'user' => 'U-marta', 'text' => 'Necesito tu ok', 'thread_ts' => '200.1'],
                ['ts' => '200.5', 'user' => 'U-me', 'text' => 'Ok, lo cierro', 'thread_ts' => '200.1'],
            ]],
            'users.info' => ['ok' => true, 'user' => [
                'id' => $request['user'],
                'name' => $request['user'],
                'profile' => [],
            ]],
            default => ['ok' => false, 'error' => 'unknown_method'],
        });
    });

    $items = app(SlackHistory::class)->collect($account, CarbonImmutable::now()->subHour());

    expect(collect($items)->pluck('ts')->all())->toContain('200.5')
        ->and(collect($items)->firstWhere(fn ($item) => $item->ts === '200.5')?->mine)->toBeTrue();
});

test('when the slack pipeline is on the watcher hands own replies to comms', function () {
    Config::set('comms.pipeline.slack', true);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'watch_cursor' => (string) (time() - 60),
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U1'],
        'scopes' => Provider::Slack->scopes(),
    ]);
    $ts = sprintf('%.6F', microtime(true));

    Http::fake(function (Request $request) use ($ts) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();

        if ($method !== 'search.messages') {
            return Http::response(match ($method) {
                'users.list' => ['ok' => true, 'members' => [
                    ['id' => 'U1', 'name' => 'me', 'profile' => ['real_name' => 'Me', 'email' => 'me@example.com']],
                    ['id' => 'U5', 'name' => 'julian', 'profile' => ['real_name' => 'Julián', 'email' => 'julian@cm.com']],
                ]],
                'users.conversations' => ['ok' => true, 'channels' => [['id' => 'D5', 'is_im' => true, 'user' => 'U5']]],
                'conversations.replies', 'conversations.history' => ['ok' => true, 'messages' => []],
                default => ['ok' => true],
            });
        }

        $matches = str_starts_with((string) $request['query'], 'from:')
            ? [[
                'ts' => $ts,
                'user' => 'U1',
                'text' => 'Lo miro en el hilo',
                'thread_ts' => '100.1',
                'channel' => ['id' => 'C1', 'name' => 'producto'],
                'permalink' => 'https://cm.slack.com/archives/C1/p1?thread_ts=100.1&cid=C1',
            ]]
            : [];

        return Http::response(['ok' => true, 'messages' => ['matches' => $matches]]);
    });

    expect(app(AlertInbox::class)->check($account, AlertSource::Slack))->toBe([])
        ->and(CommsMessage::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('direction'))->toBe('out')
        ->and(CommsMessage::query()->whereBelongsTo($user)->value('text'))->toBe('Lo miro en el hilo');
});

test('candidate handoff marks the users own slack mail as outgoing', function () {
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U1'],
    ]);
    $candidate = new InboxCandidate(
        source: AlertSource::Slack,
        externalId: "{$account->id}:C1:9.9",
        account: 'me@example.com',
        from: new MailAddress('Me', 'me@example.com'),
        to: [],
        cc: [],
        subject: 'Canal #producto',
        excerpt: 'Hecho',
        receivedAt: CarbonImmutable::now(),
        labels: ['mention'],
        isReply: true,
        threadKey: "{$account->id}:C1:1.1",
    );

    $event = app(CandidateHandoff::class)->accept($account, $candidate);

    expect($event)->not->toBeNull()
        ->and(CommsMessage::query()->whereBelongsTo($user)->sole()->direction)->toBe('out');
});
