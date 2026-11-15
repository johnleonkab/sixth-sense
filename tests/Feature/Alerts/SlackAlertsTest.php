<?php

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Judges\FakeRelevanceJudge;
use App\Alerts\RelevanceJudge;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Avisos\Engine;
use App\Connections\Provider;
use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Jobs\CheckAccountForAlerts;
use App\Models\AlertSetting;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Slack answering search.messages with these matches, and a small workspace.
 *
 * @param  list<array<string, mixed>>  $toMe  Direct and group messages.
 * @param  list<array<string, mixed>>  $mentions
 */
function fakeSlackInbox(array $toMe = [], array $mentions = []): void
{
    // Faked once per test; later calls only change what search answers.
    $alreadyFaked = isset(test()->slackInbox);
    test()->slackInbox = ['to' => $toMe, 'mentions' => $mentions];

    if ($alreadyFaked) {
        return;
    }

    Http::fake(function (Request $request) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();
        $inbox = test()->slackInbox;

        return Http::response(match ($method) {
            'search.messages' => ['ok' => true, 'messages' => ['matches' => str_starts_with($request['query'], 'to:') ? $inbox['to'] : $inbox['mentions']]],
            'users.list' => ['ok' => true, 'members' => [
                ['id' => 'U1', 'name' => 'jhon', 'profile' => ['real_name' => 'John León', 'display_name' => 'jhon']],
                ['id' => 'U5', 'name' => 'julian', 'profile' => ['real_name' => 'Julián Soto', 'display_name' => 'julian', 'email' => 'julian@cm.com']],
            ]],
            'users.conversations' => ['ok' => true, 'channels' => [['id' => 'D5', 'is_im' => true, 'user' => 'U5'], ['id' => 'C1', 'name' => 'producto']]],
            'conversations.replies', 'conversations.history' => ['ok' => true, 'messages' => []],
            default => ['ok' => false, 'error' => 'unknown_method'],
        });
    });
}

/**
 * @return array<string, mixed>
 */
function slackMatch(string $ts, string $user, string $text, array $channel = ['id' => 'D5', 'is_im' => true], array $extra = []): array
{
    return ['ts' => $ts, 'user' => $user, 'text' => $text, 'channel' => $channel, 'permalink' => "https://cm.slack.com/archives/{$channel['id']}/p".str_replace('.', '', $ts), ...$extra];
}

beforeEach(function () {
    $this->travelTo('2026-10-08 10:00:00');
    $this->app->instance(RelevanceJudge::class, $this->judge = new FakeRelevanceJudge);
    $this->app->instance(ReplyPlanner::class, $this->planner = new FakeReplyPlanner);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->account = ConnectedAccount::factory()->for($this->user)->create([
        'provider' => Provider::Slack,
        'external_account_id' => 'T1:U1',
        'email' => 'jhon@cm.com',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'scopes' => Provider::Slack->scopes(),
        'metadata' => ['team_id' => 'T1', 'team_name' => 'CoverManager', 'user_id' => 'U1'],
        // Five minutes ago.
        'watch_cursor' => (string) (now()->getTimestamp() - 300),
    ]);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Slack, 'enabled' => true]);
    Event::fake([InboxItemCreated::class, InboxItemUpdated::class]);
    $this->now = now()->getTimestamp();
});

test('the first check only remembers where slack is, so old messages never alert', function () {
    $this->account->update(['watch_cursor' => null]);
    fakeSlackInbox([slackMatch("{$this->now}.000100", 'U5', 'Necesito que revises el contrato antes del viernes')]);

    expect(app(Engine::class)->check($this->account, AlertSource::Slack))->toBe([])
        ->and((float) $this->account->refresh()->watch_cursor)->toEqualWithDelta($this->now, 1);

    Http::assertNothingSent();
});

test('a direct message becomes an aviso and stores why', function () {
    fakeSlackInbox([slackMatch("{$this->now}.000100", 'U5', '¿Puedes revisar el contrato antes del viernes?')]);

    $alerts = app(Engine::class)->check($this->account, AlertSource::Slack);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->source)->toBe(AlertSource::Slack)
        ->and($alerts[0]->refresh()->status)->toBe(AlertStatus::Pending)
        ->and($alerts[0]->external_id)->toBe("{$this->account->id}:D5:{$this->now}.000100")
        ->and($alerts[0]->thread_key)->toBe("{$this->account->id}:D5")
        ->and($alerts[0]->title)->toBe('Mensaje directo')
        ->and($alerts[0]->actor_name)->toBe('Julián Soto')
        ->and($alerts[0]->why)->toBe('Mensaje directo de Julián Soto.')
        ->and($alerts[0]->proposal)->toBeNull()
        ->and($alerts[0]->state())->toBe('important')
        ->and((float) $this->account->refresh()->watch_cursor)->toBe((float) "{$this->now}.0001");

    Event::assertDispatchedTimes(InboxItemCreated::class, 1);
    Event::assertNotDispatched(InboxItemUpdated::class);
});

test('mentions in a thread belong to that thread', function () {
    fakeSlackInbox(mentions: [slackMatch("{$this->now}.000300", 'U5', '<@U1> necesito tu ok urgente', ['id' => 'C1', 'name' => 'producto'], ['permalink' => "https://cm.slack.com/archives/C1/p1?thread_ts={$this->now}.000100&cid=C1"])]);

    $alert = app(Engine::class)->check($this->account, AlertSource::Slack)[0];

    expect($alert->title)->toBe('Mención en #producto')
        ->and($alert->thread_key)->toBe("{$this->account->id}:C1:{$this->now}.000100");
});

test('the user own messages, apps and messages already seen are left out, and a short direct message is still an aviso', function () {
    fakeSlackInbox([
        slackMatch("{$this->now}.000100", 'U1', 'Mío: necesito revisar'),
        slackMatch("{$this->now}.000200", 'U5', 'App: revisa', extra: ['bot_id' => 'B1']),
        slackMatch((string) ($this->now - 3600), 'U5', 'Viejo: revisa'),
        slackMatch("{$this->now}.000300", 'U5', 'Gracias!'),
    ]);

    $listed = app(Engine::class)->check($this->account, AlertSource::Slack);

    expect($listed)->toHaveCount(1)
        ->and($listed[0]->body)->toBe('Gracias!')
        ->and($listed[0]->why)->toBe('Mensaje directo de Julián Soto.');

    expect(app(Engine::class)->check($this->account, AlertSource::Slack))->toBe([]);
});

test('each direct message is its own aviso', function () {
    fakeSlackInbox([
        slackMatch("{$this->now}.000300", 'U5', 'Es urgente, necesito la firma hoy'),
        slackMatch("{$this->now}.000100", 'U5', 'Hola'),
    ]);

    $listed = app(Engine::class)->check($this->account, AlertSource::Slack);

    expect($listed)->toHaveCount(2)
        ->and($this->user->inboxItems()->count())->toBe(2)
        ->and($this->user->inboxItems()->orderBy('id')->pluck('body')->all())->toBe(['Hola', 'Es urgente, necesito la firma hoy']);

    Event::assertDispatchedTimes(InboxItemCreated::class, 2);
});

test('a later direct message is a new aviso even when an earlier one is still open', function () {
    fakeSlackInbox([slackMatch("{$this->now}.000100", 'U5', '¿Puedes revisar el contrato?')]);
    $first = app(Engine::class)->check($this->account, AlertSource::Slack)[0];

    fakeSlackInbox([slackMatch("{$this->now}.000500", 'U5', 'Gracias!')]);
    $second = app(Engine::class)->check($this->account, AlertSource::Slack)[0];

    expect($second->is($first))->toBeFalse()
        ->and($this->user->inboxItems()->count())->toBe(2)
        ->and($first->refresh()->status)->toBe(AlertStatus::Pending);

    Event::assertDispatchedTimes(InboxItemCreated::class, 2);
});

test('a new direct message after a resolved one is a second aviso', function () {
    fakeSlackInbox([slackMatch("{$this->now}.000100", 'U5', '¿Puedes revisar el contrato?')]);
    $alert = app(Engine::class)->check($this->account, AlertSource::Slack)[0];
    $alert->refresh()->close(AlertStatus::Resolved, 'replied');

    fakeSlackInbox([slackMatch("{$this->now}.000500", 'U5', 'Ahora necesito la firma')]);
    $second = app(Engine::class)->check($this->account, AlertSource::Slack)[0];

    expect($second->is($alert))->toBeFalse()
        ->and($this->user->inboxItems()->count())->toBe(2)
        ->and($alert->refresh()->status)->toBe(AlertStatus::Resolved)
        ->and($alert->resolution)->toBe('replied');
});

test('every minute slack accounts of users who want its alerts are checked', function () {
    Queue::fake();

    $this->artisan('alerts:watch')->assertSuccessful();

    Queue::assertPushed(CheckAccountForAlerts::class, fn (CheckAccountForAlerts $job) => $job->account->is($this->account) && $job->source === AlertSource::Slack);
});
