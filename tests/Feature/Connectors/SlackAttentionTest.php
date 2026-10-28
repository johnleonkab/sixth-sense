<?php

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\Intents\IntentAssigner;
use App\Alerts\Intents\IntentClassifier;
use App\Alerts\Intents\IntentDecision;
use App\Analytics\ProductAnalytics;
use App\Analytics\RecordingProductAnalytics;
use App\Connections\Provider;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Slack\SlackAttention;
use App\Jobs\LearnSlackAttention;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('a month of slack becomes a profile the next triage can read', function () {
    $user = User::factory()->create(['name' => 'John León']);
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'external_account_id' => 'T1:U1',
        'email' => 'jhon@cm.com',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'scopes' => Provider::Slack->scopes(),
        'metadata' => ['team_id' => 'T1', 'team_name' => 'CoverManager', 'user_id' => 'U1'],
    ]);
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);
    Http::preventStrayRequests();
    fakeAttentionSlack();

    (new LearnSlackAttention($user))->handle(app(SlackAttention::class), $recording);

    $profile = $user->slackAttentionProfile()->first();
    $secret = 'presupuesto-secreto-q4';

    expect($profile)->not->toBeNull()
        ->and($profile->signals['threads'])->toBe(5)
        ->and($profile->signals['replied'])->toBe(2)
        ->and($profile->signals['missed'])->toBe(1)
        ->and($profile->signals['other'])->toBe(1)
        ->and($profile->signals['noise'])->toBe(1)
        ->and($profile->signals['people'][0]['name'])->toBe('Julián Soto')
        ->and($profile->signals['owned_channels'])->toBe(['#producto'])
        ->and($profile->body)->toContain('Contestó 2')
        ->and($profile->body)->toContain('Julián Soto')
        ->and($profile->body)->toContain('#producto')
        ->and($profile->body)->toContain('#general')
        ->and($profile->body)->not->toContain($secret)
        ->and(json_encode($profile->signals))->not->toContain($secret);

    expect($recording->events)->toBe([[
        'event' => 'slack_attention_profile_built',
        'distinct_id' => (string) $user->id,
        'properties' => ['threads' => 5, 'replied' => 2, 'missed' => 1],
    ]]);

    $spy = new class implements IntentClassifier
    {
        public string $context = '';

        public function classify(User $user, InboxCandidate $candidate, array $intents, string $context): IntentDecision
        {
            $this->context = $context;

            return new IntentDecision(false, 'Nada que hacer.', 0, null, 'Nada');
        }
    };
    app()->instance(IntentClassifier::class, $spy);

    app(IntentAssigner::class)->assign($user, new InboxCandidate(
        source: AlertSource::Slack,
        externalId: 'new-1',
        account: $account->email,
        from: new MailAddress('Julián Soto', 'julian@cm.com'),
        to: [],
        cc: [],
        subject: 'Mensaje directo',
        excerpt: '¿Sigues con el '.$secret.'?',
        receivedAt: CarbonImmutable::parse('2026-10-28 10:00', 'Europe/Madrid'),
        threadKey: 'dm-1',
    ));

    expect($spy->context)->toContain('Política de atención en Slack')
        ->and($spy->context)->toContain('Contestó 2')
        ->and($spy->context)->not->toContain($secret);
});

test('someone without slack search is not profiled', function () {
    $user = User::factory()->create();

    expect(app(SlackAttention::class)->learn($user))->toBeNull()
        ->and($user->slackAttentionProfile()->exists())->toBeFalse();
});

test('the weekly command queues one profile per slack workspace', function () {
    Queue::fake();
    $slack = User::factory()->create();
    ConnectedAccount::factory()->for($slack)->create([
        'provider' => Provider::Slack,
        'scopes' => Provider::Slack->scopes(),
        'expires_at' => null,
    ]);
    User::factory()->create();

    $this->artisan('slack:learn-attention')->assertSuccessful();

    Queue::assertPushed(LearnSlackAttention::class, 1);
    Queue::assertPushed(LearnSlackAttention::class, fn (LearnSlackAttention $job): bool => $job->user->is($slack));
});

function fakeAttentionSlack(): void
{
    $dm = ['id' => 'D5', 'is_im' => true, 'user' => 'U5'];
    $producto = ['id' => 'C1', 'name' => 'producto'];
    $squad = ['id' => 'C2', 'name' => 'squad'];
    $general = ['id' => 'C3', 'name' => 'general'];
    $random = ['id' => 'C4', 'name' => 'random'];
    $secret = 'presupuesto-secreto-q4';

    $matches = [
        attentionMatch('1761600000.000100', 'U5', "¿puedes mirar el {$secret}?", $dm),
        attentionMatch('1761603600.000100', 'U1', 'sí, lo miro', $dm),
        attentionMatch('1761600001.000100', 'U5', '<@U1> el doc de producto', $producto, '1761600001.000100'),
        attentionMatch('1761607200.000100', 'U1', 'hecho', $producto, '1761600001.000100'),
        attentionMatch('1761600002.000100', 'U5', '<@U1> ¿cómo va el despliegue?', $squad, '1761600002.000100'),
        attentionMatch('1761600003.000100', 'U5', '<@U1> mira el doc', $general, '1761600003.000100'),
        attentionMatch('1761600004.000100', 'U9', '<@U1> aviso del canal', $random, '1761600004.000100'),
    ];

    $replies = [
        '1761600001.000100' => [
            ['ts' => '1761600001.000100', 'user' => 'U5', 'text' => '<@U1> el doc de producto', 'thread_ts' => '1761600001.000100'],
            ['ts' => '1761607200.000100', 'user' => 'U1', 'text' => 'hecho', 'thread_ts' => '1761600001.000100'],
        ],
        '1761600002.000100' => [
            ['ts' => '1761600002.000100', 'user' => 'U5', 'text' => '<@U1> ¿cómo va el despliegue?', 'thread_ts' => '1761600002.000100'],
            ['ts' => '1761601800.000100', 'user' => 'U9', 'text' => 'ya lo miro yo', 'thread_ts' => '1761600002.000100'],
        ],
        '1761600003.000100' => [
            ['ts' => '1761600003.000100', 'user' => 'U5', 'text' => '<@U1> mira el doc', 'thread_ts' => '1761600003.000100'],
            ['ts' => '1761610000.000100', 'user' => 'U5', 'text' => '<@U1> ¿viste esto?', 'thread_ts' => '1761600003.000100'],
        ],
        '1761600004.000100' => [
            ['ts' => '1761600004.000100', 'user' => 'U9', 'text' => '<@U1> aviso del canal', 'thread_ts' => '1761600004.000100'],
        ],
    ];

    Http::fake(function (Request $request) use ($matches, $replies) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();

        return Http::response(match ($method) {
            'search.messages' => ['ok' => true, 'messages' => ['matches' => $matches]],
            'users.list' => ['ok' => true, 'members' => [
                ['id' => 'U1', 'name' => 'jhon', 'profile' => ['real_name' => 'John León', 'display_name' => 'jhon']],
                ['id' => 'U5', 'name' => 'julian', 'profile' => ['real_name' => 'Julián Soto', 'display_name' => 'julian']],
                ['id' => 'U9', 'name' => 'marta', 'profile' => ['real_name' => 'Marta Ruiz', 'display_name' => 'marta']],
            ]],
            'users.conversations' => ['ok' => true, 'channels' => [
                ['id' => 'D5', 'is_im' => true, 'user' => 'U5'],
                ['id' => 'C1', 'name' => 'producto'],
                ['id' => 'C2', 'name' => 'squad'],
                ['id' => 'C3', 'name' => 'general'],
                ['id' => 'C4', 'name' => 'random'],
            ]],
            'conversations.history' => ['ok' => true, 'messages' => $request['channel'] === 'D5' ? [
                ['ts' => '1761603600.000100', 'user' => 'U1', 'text' => 'sí, lo miro'],
                ['ts' => '1761600000.000100', 'user' => 'U5', 'text' => '¿puedes mirar el presupuesto-secreto-q4?'],
            ] : []],
            'conversations.replies' => ['ok' => true, 'messages' => $replies[$request['ts']] ?? []],
            default => ['ok' => false, 'error' => 'unknown_method'],
        });
    });
}

/**
 * @param  array<string, mixed>  $channel
 * @return array<string, mixed>
 */
function attentionMatch(string $ts, string $author, string $text, array $channel, ?string $threadTs = null): array
{
    return [
        'ts' => $ts,
        'user' => $author,
        'text' => $text,
        'thread_ts' => $threadTs,
        'channel' => $channel,
        'permalink' => "https://cm.slack.com/archives/{$channel['id']}/p".str_replace('.', '', $ts),
    ];
}
