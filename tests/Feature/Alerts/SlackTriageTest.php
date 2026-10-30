<?php

use App\Alerts\AlertInbox;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\InboxCandidate;
use App\Alerts\Intents\FakeIntentClassifier;
use App\Alerts\Intents\IntentAssigner;
use App\Alerts\Intents\IntentClassifier;
use App\Alerts\Intents\IntentDecision;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackThreadReader;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Analytics\ProductAnalytics;
use App\Analytics\RecordingProductAnalytics;
use App\Connections\Provider;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\FakeDocuments;
use App\Connectors\Mail\MailAddress;
use App\Jobs\PromoteUnansweredSlack;
use App\Jobs\ScheduleTask;
use App\Models\ConnectedAccount;
use App\Models\SlackAttentionProfile;
use App\Models\SlackTriageDecision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('a direct message she is unsure about becomes a reply and keeps the reason', function () {
    [$user] = slackPerson();
    decide($user, SlackAction::Ignore, 'Julián te pregunta por el contrato y el plazo es el viernes.', uncertain: true);

    $item = app(IntentAssigner::class)->assign($user, slackMessage('dm', 'Mensaje directo', '¿lo miramos?'));

    expect($item)->not->toBeNull()
        ->and($item->why)->toBe('Julián te pregunta por el contrato y el plazo es el viernes.')
        ->and($item->status)->toBe(AlertStatus::Pending)
        ->and($user->slackTriageDecisions()->sole()->action)->toBe('reply');
});

test('an ok in a direct message is ignored and does not open an alert', function () {
    [$user, $recording] = slackPerson();
    decide($user, SlackAction::Ignore, 'Informativo, sin nada que hacer.');

    $item = app(IntentAssigner::class)->assign($user, slackMessage('dm', 'Mensaje directo', 'ok'));

    expect($item)->toBeNull()
        ->and($user->inboxItems()->count())->toBe(0)
        ->and($user->slackTriageDecisions()->sole()->action)->toBe('ignore')
        ->and($recording->events[0]['event'])->toBe('slack_triage_decided')
        ->and($recording->events[0]['properties'])->toMatchArray([
            'action' => 'ignore',
            'place' => 'dm',
            'policy_present' => true,
        ])
        ->and($recording->events[0]['properties'])->not->toHaveKey('reason');
});

test('a channel the profile gives to someone else is a redirect and is not sent', function () {
    [$user, $recording] = slackPerson();
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);
    app()->instance(Documents::class, new FakeDocuments);
    $planner = new FakeReplyPlanner;
    app()->instance(ReplyPlanner::class, $planner);
    decide($user, SlackAction::Reply, 'Preguntan por el despliegue.', target: 'Marta Ruiz');

    $item = app(IntentAssigner::class)->assign($user, slackMessage('channel', 'Canal #squad', '¿cómo va el despliegue?'))->fresh();

    expect($item->proposal['reply'])->toBeNull()
        ->and($item->proposal['redirect']['to'])->toBe('Marta Ruiz')
        ->and($item->proposal['summary'])->toBe('Pasárselo a Marta Ruiz.')
        ->and($item->proposal['context'])->toContain('confirmar')
        ->and($planner->planned)->toHaveCount(1)
        ->and($chats->sent)->toBe([])
        ->and($user->slackTriageDecisions()->sole()->has_target)->toBeTrue()
        ->and($user->slackTriageDecisions()->sole()->action)->toBe('redirect')
        ->and(collect($recording->events)->firstWhere('event', 'slack_reply_planned')['properties'])->toMatchArray([
            'action' => 'redirect',
            'place' => 'channel',
            'sufficient' => false,
            'agent_asked' => false,
            'conflict' => false,
        ]);
});

test('a task becomes an agenda task and not a chat reply', function () {
    Queue::fake([ScheduleTask::class]);
    [$user] = slackPerson();
    $planner = new FakeReplyPlanner;
    app()->instance(ReplyPlanner::class, $planner);
    decide($user, SlackAction::Task, 'Cuando puedas, revisa el documento.', goal: 'Revisar el documento');

    $item = app(IntentAssigner::class)->assign($user, slackMessage('dm', 'Mensaje directo', 'Cuando puedas revisa el documento'))->fresh();

    expect($item->proposal['reply'])->toBeNull()
        ->and($planner->planned)->toBe([])
        ->and($item->agendaTask)->not->toBeNull()
        ->and($item->agendaTask->title)->toBe('Revisar el documento')
        ->and($user->slackTriageDecisions()->sole()->action)->toBe('task');

    Queue::assertPushed(ScheduleTask::class);
});

test('a question in a channel she owns is triaged quietly and raised when it stays alone', function () {
    $this->travelTo('2026-10-28 10:00:00');
    [$user, $recording, $account] = slackPerson();
    $now = now()->getTimestamp();
    $account->update(['watch_cursor' => (string) ($now - 300)]);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($now) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();
        $query = isset($request['query']) ? (string) $request['query'] : '';

        return Http::response(match ($method) {
            'search.messages' => ['ok' => true, 'messages' => ['matches' => str_starts_with($query, 'in:#') ? [[
                'ts' => "{$now}.000100",
                'user' => 'U5',
                'text' => '¿alguien sabe cómo va el despliegue?',
                'channel' => ['id' => 'C1', 'name' => 'producto'],
                'permalink' => 'https://cm.slack.com/archives/C1/p'.$now,
            ]] : []]],
            'users.list' => ['ok' => true, 'members' => [
                ['id' => 'U1', 'name' => 'jhon', 'profile' => ['real_name' => 'John León']],
                ['id' => 'U5', 'name' => 'julian', 'profile' => ['real_name' => 'Julián Soto']],
                ['id' => 'U9', 'name' => 'marta', 'profile' => ['real_name' => 'Marta Ruiz']],
            ]],
            'users.conversations' => ['ok' => true, 'channels' => [
                ['id' => 'C1', 'name' => 'producto'],
            ]],
            'conversations.replies' => ['ok' => true, 'messages' => [[
                'ts' => "{$now}.000100",
                'user' => 'U5',
                'text' => '¿alguien sabe cómo va el despliegue?',
            ]]],
            default => ['ok' => false, 'error' => 'unknown_method'],
        });
    });

    expect(app(AlertInbox::class)->check($account, AlertSource::Slack))->toBe([])
        ->and($user->inboxItems()->count())->toBe(0);

    $decision = $user->slackTriageDecisions()->sole();

    expect($decision->action)->toBe('ignore')
        ->and($decision->place)->toBe('channel')
        ->and($decision->channel)->toBe('#producto');

    $this->travel(5)->hours();
    app(PromoteUnansweredSlack::class)->handle(
        app(SlackThreadReader::class),
        app(IntentAssigner::class),
        app(ProductAnalytics::class),
    );

    $alert = $user->inboxItems()->sole();

    expect($alert->status)->toBe(AlertStatus::Pending)
        ->and($alert->why)->toContain('#producto')
        ->and($decision->refresh()->action)->toBe('reply')
        ->and($decision->promoted_at)->not->toBeNull()
        ->and(collect($recording->events)->pluck('properties.action')->all())->toBe(['ignore', 'reply']);
});

test('a question someone else already answered stays quiet', function () {
    $this->travelTo('2026-10-28 10:00:00');
    [$user, , $account] = slackPerson();
    $ts = now()->getTimestamp().'.000100';
    $ref = "{$account->id}:C1:{$ts}";
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($ts) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();

        return Http::response(match ($method) {
            'users.list' => ['ok' => true, 'members' => [
                ['id' => 'U1', 'name' => 'jhon', 'profile' => ['real_name' => 'John León']],
                ['id' => 'U9', 'name' => 'marta', 'profile' => ['real_name' => 'Marta Ruiz']],
            ]],
            'users.conversations' => ['ok' => true, 'channels' => [['id' => 'C1', 'name' => 'producto']]],
            'conversations.replies' => ['ok' => true, 'messages' => [
                ['ts' => $ts, 'user' => 'U5', 'text' => '¿alguien sabe cómo va el despliegue?'],
                ['ts' => ((int) $ts + 100).'.000100', 'user' => 'U9', 'text' => 'Ya está desplegado en producción'],
            ]],
            default => ['ok' => false, 'error' => 'unknown_method'],
        });
    });

    $decision = SlackTriageDecision::query()->create([
        'user_id' => $user->id,
        'external_id' => $ref,
        'action' => 'ignore',
        'place' => 'channel',
        'owner' => 'unknown',
        'reason' => 'Pregunta al canal.',
        'uncertain' => false,
        'has_target' => false,
        'already_answered' => false,
        'policy_present' => true,
        'thread_key' => $ref,
        'channel' => '#producto',
        'candidate' => slackMessage('channel', 'Canal #producto', '¿alguien sabe cómo va el despliegue?', $ref)->toArray(),
    ]);
    $decision->forceFill(['created_at' => now()->subHours(5)])->save();

    app(PromoteUnansweredSlack::class)->handle(
        app(SlackThreadReader::class),
        app(IntentAssigner::class),
        app(ProductAnalytics::class),
    );

    expect($user->inboxItems()->count())->toBe(0)
        ->and($decision->refresh()->action)->toBe('ignore')
        ->and($decision->settled_at)->not->toBeNull()
        ->and($decision->already_answered)->toBeTrue();
});

/**
 * @return array{0: User, 1: RecordingProductAnalytics, 2: ConnectedAccount}
 */
function slackPerson(): array
{
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
    SlackAttentionProfile::query()->create([
        'user_id' => $user->id,
        'body' => 'Canales que parecen suyos: #producto.',
        'signals' => [
            'threads' => 3,
            'replied' => 2,
            'missed' => 0,
            'other' => 1,
            'noise' => 0,
            'people' => [],
            'channels' => [],
            'owned_channels' => ['#producto'],
        ],
        'built_at' => now(),
    ]);
    $recording = new RecordingProductAnalytics;
    app()->instance(ProductAnalytics::class, $recording);

    return [$user, $recording, $account];
}

function decide(User $user, SlackAction $action, string $reason, bool $uncertain = false, ?string $target = null, ?string $goal = null): void
{
    $classifier = app(IntentClassifier::class);
    expect($classifier)->toBeInstanceOf(FakeIntentClassifier::class);

    $classifier->next = new IntentDecision(
        important: $action->shown(),
        reason: $reason,
        relevance: $action->shown() ? 80 : 10,
        intentId: null,
        goal: $goal ?? 'El pedido',
        action: $action,
        uncertain: $uncertain,
        target: $target,
    );
}

test('a follow-up on something left aside opens a reply and records the miss', function (string $place, string $text, string $signal) {
    [$user, $recording] = slackPerson();
    decide($user, SlackAction::Ignore, 'Informativo, sin nada que hacer.');
    app(IntentAssigner::class)->assign($user, slackMessage($place, 'Mensaje directo', 'ok', 'msg-1', 'hilo-1'));

    decide($user, SlackAction::Ignore, 'Informativo, sin nada que hacer.');
    $item = app(IntentAssigner::class)->assign($user, slackMessage($place, 'Mensaje directo', $text, 'msg-2', 'hilo-1'));

    $miss = collect($recording->events)->firstWhere('event', 'slack_triage_missed');

    expect($item)->not->toBeNull()
        ->and($item->why)->toBe('Te vuelven a preguntar por algo que había dejado pasar.')
        ->and($item->status)->toBe(AlertStatus::Pending)
        ->and($user->slackTriageDecisions()->where('external_id', 'msg-1')->first()->missed_at)->not->toBeNull()
        ->and($user->slackTriageDecisions()->where('external_id', 'msg-2')->first()->action)->toBe('reply')
        ->and($miss['properties'])->toMatchArray([
            'signal' => $signal,
            'place' => $place,
            'action' => 'ignore',
        ])
        ->and($miss['properties'])->not->toHaveKey('reason');
})->with([
    'a poke' => ['dm', '¿viste esto?', 're_ping'],
    'a second mention' => ['mention', 'te lo dejo otra vez', 're_mention'],
    'a later direct message' => ['dm', 'sigo con lo del contrato', 're_ping'],
]);

test('a later channel message without a poke or a mention stays ignored', function () {
    [$user, $recording] = slackPerson();
    decide($user, SlackAction::Ignore, 'Informativo, sin nada que hacer.');
    app(IntentAssigner::class)->assign($user, slackMessage('channel', 'Canal #producto', '¿alguien sabe cómo va?', 'msg-1', 'hilo-canal'));

    decide($user, SlackAction::Ignore, 'Informativo, sin nada que hacer.');
    $item = app(IntentAssigner::class)->assign($user, slackMessage('channel', 'Canal #producto', 'vale, lo miro yo', 'msg-2', 'hilo-canal'));

    expect($item)->toBeNull()
        ->and($user->slackTriageDecisions()->where('external_id', 'msg-1')->first()->missed_at)->toBeNull()
        ->and($user->slackTriageDecisions()->where('external_id', 'msg-2')->first()->action)->toBe('ignore')
        ->and(collect($recording->events)->pluck('event'))->not->toContain('slack_triage_missed');
});

test('a poke with nothing left aside stays ignored', function () {
    [$user, $recording] = slackPerson();
    decide($user, SlackAction::Ignore, 'Informativo, sin nada que hacer.');

    $item = app(IntentAssigner::class)->assign($user, slackMessage('dm', 'Mensaje directo', '¿viste esto?', 'msg-1', 'hilo-1'));

    expect($item)->toBeNull()
        ->and(collect($recording->events)->pluck('event'))->not->toContain('slack_triage_missed');
});

function slackMessage(string $place, string $subject, string $text, ?string $ref = null, ?string $thread = null): InboxCandidate
{
    return new InboxCandidate(
        source: AlertSource::Slack,
        externalId: $ref ?? $place.'-1',
        account: 'jhon@cm.com',
        from: new MailAddress('Julián Soto', 'julian@cm.com'),
        to: [],
        cc: [],
        subject: $subject,
        excerpt: $text,
        receivedAt: CarbonImmutable::parse('2026-10-28 10:00', 'Europe/Madrid'),
        labels: [$place],
        threadKey: $thread ?? $ref,
    );
}
