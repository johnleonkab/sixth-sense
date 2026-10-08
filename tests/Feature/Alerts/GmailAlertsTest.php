<?php

use App\Alerts\AlertInbox;
use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\Judgement;
use App\Alerts\Judges\FakeRelevanceJudge;
use App\Alerts\RelevanceJudge;
use App\Events\InboxItemCreated;
use App\Jobs\CheckAccountForAlerts;
use App\Models\AlertSetting;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function incoming(string $id, array $overrides = []): array
{
    $headers = [
        ['name' => 'From', 'value' => $overrides['from'] ?? 'Andrés Vela <andres@grupovela.com>'],
        ['name' => 'To', 'value' => 'John León <jhon@work.com>'],
        ['name' => 'Subject', 'value' => $overrides['subject'] ?? 'Contrato: necesito la firma antes del viernes'],
        ...($overrides['headers'] ?? []),
    ];

    return [
        'id' => $id,
        'labelIds' => $overrides['labels'] ?? ['INBOX', 'UNREAD', 'CATEGORY_PERSONAL'],
        'snippet' => 'Hola John',
        'internalDate' => (string) (now()->getTimestamp() * 1000),
        'payload' => [
            'mimeType' => 'text/plain',
            'headers' => $headers,
            'body' => ['data' => rtrim(strtr(base64_encode($overrides['body'] ?? 'Necesitamos tu firma antes del viernes.'), '+/', '-_'), '=')],
        ],
    ];
}

/**
 * @param  list<array<string, mixed>>  $messages  Added since the cursor.
 */
function fakeGmailHistory(array $messages, string $newHistoryId = '200', int $historyStatus = 200): void
{
    Http::fake(function (Request $request) use ($messages, $newHistoryId, $historyStatus) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/profile') => Http::response(['historyId' => '500']),
            str_contains($url, '/history') => $historyStatus === 200
                ? Http::response(['historyId' => $newHistoryId, 'history' => [['messagesAdded' => array_map(fn ($m) => ['message' => ['id' => $m['id']]], $messages)]]])
                : Http::response([], $historyStatus),
            (bool) preg_match('~/messages/([^?]+)~', $url, $match) => Http::response(collect($messages)->firstWhere('id', $match[1]) ?? [], 200),
            default => Http::response([], 404),
        };
    });
}

beforeEach(function () {
    config(['alerts.judge' => 'fake']);
    $this->app->instance(RelevanceJudge::class, $this->judge = new FakeRelevanceJudge);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->account = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@work.com', 'watch_cursor' => '100']);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'enabled' => true]);
    Event::fake([InboxItemCreated::class]);
});

test('the first check only remembers where the inbox is, so old mail never alerts', function () {
    $this->account->update(['watch_cursor' => null]);
    fakeGmailHistory([incoming('m1')]);

    expect(app(AlertInbox::class)->check($this->account))->toBe([])
        ->and($this->account->refresh()->watch_cursor)->toBe('500');

    Event::assertNotDispatched(InboxItemCreated::class);
});

test('an important email raises an alert with why, and the browser is told', function () {
    fakeGmailHistory([incoming('m1')]);

    $alerts = app(AlertInbox::class)->check($this->account);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->title)->toBe('Contrato: necesito la firma antes del viernes')
        ->and($alerts[0]->actor_name)->toBe('Andrés Vela')
        ->and($alerts[0]->why)->toBe('Andrés Vela te pide algo: Contrato: necesito la firma antes del viernes')
        ->and($alerts[0]->external_id)->toBe("{$this->account->id}.m1")
        ->and($this->account->refresh()->watch_cursor)->toBe('200');

    Event::assertDispatched(InboxItemCreated::class, fn (InboxItemCreated $event) => $event->item->is($alerts[0])
        && $event->broadcastOn()[0]->name === "private-App.Models.User.{$this->user->id}"
        && $event->broadcastAs() === 'inbox.item.created'
        && $event->broadcastWith()['why'] === $alerts[0]->why);
});

test('noise never reaches the judge', function () {
    fakeGmailHistory([
        incoming('promo', ['labels' => ['INBOX', 'CATEGORY_PROMOTIONS']]),
        incoming('newsletter', ['headers' => [['name' => 'List-Unsubscribe', 'value' => '<mailto:x@y.z>']]]),
        incoming('robot', ['from' => 'Jira <no-reply@atlassian.net>']),
        incoming('mine', ['from' => 'John León <jhon@work.com>']),
        incoming('archived', ['labels' => ['UNREAD']]),
    ]);

    expect(app(AlertInbox::class)->check($this->account))->toBe([])
        ->and($this->judge->judged)->toBe([]);
});

test('emails the judge finds unimportant do not alert', function () {
    fakeGmailHistory([incoming('m1', ['subject' => 'Gracias por el café', 'body' => 'Un placer verte ayer.'])]);

    expect(app(AlertInbox::class)->check($this->account))->toBe([])
        ->and($this->judge->judged)->toHaveCount(1);
});

test('the same email never alerts twice', function () {
    fakeGmailHistory([incoming('m1')]);

    app(AlertInbox::class)->check($this->account);
    app(AlertInbox::class)->check($this->account->refresh());

    expect($this->user->inboxItems()->count())->toBe(1);
    Event::assertDispatchedTimes(InboxItemCreated::class, 1);
});

test('if judging fails, the cursor stays so the emails are checked again', function () {
    $this->app->instance(RelevanceJudge::class, new class implements RelevanceJudge
    {
        public function judge(User $user, InboxCandidate $candidate): Judgement
        {
            throw new RuntimeException('Gemini is down');
        }
    });
    fakeGmailHistory([incoming('m1')]);

    expect(fn () => app(AlertInbox::class)->check($this->account))->toThrow(RuntimeException::class);
    expect($this->account->refresh()->watch_cursor)->toBe('100');
});

test('when gmail forgot the old point in history it starts again from now', function () {
    fakeGmailHistory([], historyStatus: 404);

    expect(app(AlertInbox::class)->check($this->account))->toBe([])
        ->and($this->account->refresh()->watch_cursor)->toBe('500');
});

test('it only asks gmail for what was added to the inbox since the cursor', function () {
    fakeGmailHistory([]);

    app(AlertInbox::class)->check($this->account);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users/me/history')
        && $request['startHistoryId'] === '100'
        && $request['historyTypes'] === 'messageAdded'
        && $request['labelId'] === 'INBOX');
});

test('every minute it queues a check for each watched account of users who want alerts', function () {
    Queue::fake();
    $unwatched = ConnectedAccount::factory()->for($this->user)->create(['disabled_capabilities' => ['gmail.read']]);
    ConnectedAccount::factory()->create();
    $quiet = User::factory()->create();
    ConnectedAccount::factory()->for($quiet)->create();
    AlertSetting::factory()->for($quiet)->create(['enabled' => false]);

    $this->artisan('alerts:watch')->assertSuccessful();

    Queue::assertPushed(CheckAccountForAlerts::class, 1);
    Queue::assertPushed(CheckAccountForAlerts::class, fn (CheckAccountForAlerts $job) => $job->account->is($this->account) && $job->source === AlertSource::Gmail);
    Queue::assertNotPushed(CheckAccountForAlerts::class, fn (CheckAccountForAlerts $job) => $job->account->is($unwatched));
});

test('a queued check does nothing once the user switched alerts off', function () {
    Http::fake();
    $this->user->alertSettings()->update(['enabled' => false]);

    (new CheckAccountForAlerts($this->account))->handle(app(AlertInbox::class));

    Http::assertNothingSent();
});
