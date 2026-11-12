<?php

use App\Alerts\AlertSource;
use App\Comms\Context\ContextHarvest;
use App\Connections\Provider;
use App\Connectors\Slack\SlackApi;
use App\Jobs\CheckAccountForAlerts;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

test('a slack search timeout is retried and then succeeds', function () {
    $account = ConnectedAccount::factory()->for(User::factory())->create([
        'provider' => Provider::Slack,
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'scopes' => Provider::Slack->scopes(),
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U1'],
    ]);

    Http::fake([
        'slack.com/api/search.messages*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out after 20001 milliseconds')
            ->push(['ok' => true, 'messages' => ['matches' => []]]),
    ]);

    $response = app(SlackApi::class)->get($account, 'search.messages', ['query' => 'to:<@U1>', 'count' => 50]);

    expect($response['ok'])->toBeTrue()
        ->and($response['messages']['matches'])->toBe([]);
    Sleep::assertSleptTimes(1);
    Http::assertSentCount(2);
});

test('check account for alerts retries transient failures', function () {
    $job = new CheckAccountForAlerts(
        ConnectedAccount::factory()->for(User::factory())->make(),
        AlertSource::Slack,
    );

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 90]);
});

test('context harvest retries once after a transient deepseek cut', function () {
    $job = new ContextHarvest(User::factory()->make());

    expect($job->tries)->toBe(2)
        ->and($job->backoff)->toBe([120]);
});
