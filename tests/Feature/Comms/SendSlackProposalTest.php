<?php

use App\Comms\Gate\Gate;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Jobs\ProcessCommsEvent;
use App\Connections\Provider;
use App\Models\Comms\CommsProposal;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

test('a proposal addressed with a bare channel is sent in its thread, and a failed one can be sent again', function () {
    Bus::fake([ProcessCommsEvent::class]);
    $posts = [];
    Http::fake(function (Request $request) use (&$posts) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();

        if ($method !== 'chat.postMessage') {
            return Http::response(['ok' => true]);
        }

        $posts[] = $request->data();

        return Http::response(count($posts) === 1
            ? ['ok' => false, 'error' => 'channel_not_found']
            : ['ok' => true, 'ts' => '9.9']);
    });

    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'external_account_id' => 'T1:U1',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'scopes' => Provider::Slack->scopes(),
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U1'],
    ]);
    $proposal = CommsProposal::factory()->for($user)->create([
        'target' => ['channel' => 'C1', 'thread' => '1710000000.000100'],
        'payload' => [
            'text' => 'Already read it!',
            'to' => 'C1',
            'to_label' => '#producto',
            'thread_id' => '1710000000.000100',
        ],
    ]);

    app(Gate::class)->approve($proposal);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Failed)
        ->and($proposal->pendingAction->error)->toContain('channel_not_found')
        ->and($posts)->toHaveCount(1)
        ->and($posts[0]['channel'])->toBe('C1')
        ->and($posts[0]['thread_ts'])->toBe('1710000000.000100')
        ->and($posts[0]['text'])->toBe('Already read it!');

    app(Gate::class)->approve($proposal->refresh());

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Executed)
        ->and($proposal->external_id)->toBe("{$account->id}:C1:9.9")
        ->and($posts)->toHaveCount(2)
        ->and($posts[1]['text'])->toBe('Already read it!');
});
