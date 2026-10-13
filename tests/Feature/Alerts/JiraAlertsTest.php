<?php

use App\Alerts\AlertFollowUp;
use App\Alerts\AlertInbox;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Judges\FakeRelevanceJudge;
use App\Alerts\RelevanceJudge;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connections\Provider;
use App\Jobs\CheckAccountForAlerts;
use App\Models\AlertSetting;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function jiraMention(string $id, string $author, string $text, string $created, bool $mentionsMe = true): array
{
    return ['id' => $id, 'author' => ['accountId' => $author, 'displayName' => $author === 'u-me' ? 'John León' : 'Pablo Gil'], 'created' => $created, 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ...($mentionsMe ? [['type' => 'mention', 'attrs' => ['id' => 'u-me', 'text' => '@John León']]] : []),
        ['type' => 'text', 'text' => $text],
    ]]]]];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00', 'UTC'));
    $this->app->instance(RelevanceJudge::class, new FakeRelevanceJudge);
    $this->app->instance(ReplyPlanner::class, new FakeReplyPlanner);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->account = ConnectedAccount::factory()->for($this->user)->create([
        'provider' => Provider::Atlassian, 'external_account_id' => 'cloud-1:u-me', 'email' => 'jhon@cm.com', 'access_token' => 'tok',
        'expires_at' => now()->addHour(), 'scopes' => Provider::Atlassian->scopes(),
        'metadata' => ['cloud_id' => 'cloud-1', 'site_name' => 'covermanager', 'site_url' => 'https://cm.atlassian.net', 'account_id' => 'u-me'],
        'rules' => '- Proyectos: CPD', 'watch_cursor' => '2026-10-13T09:58:00+00:00',
    ]);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Jira, 'enabled' => true]);

    $this->comments = [
        jiraMention('20', 'u-pablo', ' necesito tu ayuda para decidir si entra en el sprint', '2026-10-13T09:59:00.000+0000'),
        jiraMention('19', 'u-pablo', ' gracias!', '2026-10-13T09:59:30.000+0000', mentionsMe: false),
    ];
    Http::fake(function (Request $request) {
        return match (true) {
            str_ends_with($request->url(), 'search/jql') => Http::response(['issues' => [['key' => 'CPD-412', 'fields' => ['summary' => 'Ver el total', 'issuetype' => ['name' => 'Story'], 'status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']], 'updated' => '2026-10-13T09:59:00.000+0000']]]]),
            str_contains($request->url(), '/comment') => Http::response(['comments' => $this->comments]),
            default => Http::response(['key' => 'CPD-412', 'fields' => ['summary' => 'Ver el total', 'issuetype' => ['name' => 'Story'], 'status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']], 'description' => null]]),
        };
    });
});

test('a mention asking for help becomes an alert waiting for an answer, with a proposal', function () {
    $listed = app(AlertInbox::class)->check($this->account, AlertSource::Jira);

    expect($listed)->toHaveCount(1);

    $alert = $listed[0]->refresh();

    expect($alert->source)->toBe(AlertSource::Jira)
        ->and($alert->external_id)->toBe("{$this->account->id}:CPD-412:20")
        ->and($alert->thread_key)->toBe("{$this->account->id}:CPD-412")
        ->and($alert->title)->toBe('CPD-412 · Ver el total')
        ->and($alert->actor_name)->toBe('Pablo Gil')
        ->and($alert->state())->toBe('ready')
        ->and($alert->external_url)->toBe('https://cm.atlassian.net/browse/CPD-412?focusedCommentId=20')
        ->and($this->account->refresh()->watch_cursor)->toBe('2026-10-13T10:00:00+00:00');
});

test('answering in jira resolves it', function () {
    $alert = app(AlertInbox::class)->check($this->account, AlertSource::Jira)[0];
    $this->comments[] = jiraMention('21', 'u-me', 'Sí, entra.', '2026-10-13T10:05:00.000+0000', mentionsMe: false);

    app(AlertFollowUp::class)->checkReplies($this->user);

    expect($alert->refresh()->status)->toBe(AlertStatus::Resolved);
});

test('every minute jira sites of users who want its alerts are checked', function () {
    Queue::fake();

    $this->artisan('alerts:watch')->assertSuccessful();

    Queue::assertPushed(CheckAccountForAlerts::class, fn (CheckAccountForAlerts $job) => $job->account->is($this->account) && $job->source === AlertSource::Jira);
});
