<?php

use App\Actions\ActionStatus;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\RecipientResolver;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->user = User::factory()->create();
});

function runSlackTool(string $tool, array $arguments = []): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('unread direct messages and mentions are shown numbered with why', function () {
    runSlackTool('show_slack_unread')
        ->assertJsonPath('canvas.view', 'slack.unread')
        ->assertJsonPath('canvas.props.title', '2 pendientes en Slack')
        ->assertJsonPath('canvas.props.items.0.reason', 'dm')
        ->assertJsonPath('canvas.props.items.1.message.mentions_me', true)
        ->assertJsonPath('output.unread.1.why', 'te mencionan');
});

test('a message is shown in its thread, highlighted', function () {
    runSlackTool('show_slack_conversation', ['message_id' => 'demo:C01:1001.000400'])
        ->assertJsonPath('canvas.view', 'slack.conversation')
        ->assertJsonPath('canvas.props.thread_ref', 'demo:C01:1001.000100')
        ->assertJsonPath('canvas.props.focus_ref', 'demo:C01:1001.000400')
        ->assertJsonCount(4, 'canvas.props.messages');
});

test('a conversation can be named like a person speaks', function (string $name, string $label) {
    runSlackTool('show_slack_conversation', ['conversation' => $name])->assertJsonPath('canvas.props.conversation.label', $label);
})->with([['#producto', '#producto'], ['pablo', 'Pablo Gil']]);

test('people can be looked up for their email', function () {
    runSlackTool('find_slack_people', ['query' => 'lucia'])
        ->assertJsonPath('output.people.0.email', 'lucia@covermanager.test')
        ->assertJsonPath('output.people.0.title', 'Ingeniería');

    expect(app(RecipientResolver::class)->resolve($this->user, 'Lucía')[0]->email)->toBe('lucia@covermanager.test');
});

test('searching shows numbered messages, or only answers by voice', function () {
    $response = runSlackTool('search_slack_messages', ['text' => 'reembolso', 'from' => 'Pablo'])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.items.0.tool', 'show_slack_conversation')
        ->assertJsonPath('output.total', 3);

    expect($response->json('canvas.props.ui'))->toContain('Chips(["«reembolso»", "de Pablo"])')->toContain('match: "reembolso"')->toBeValidOpenUi();

    runSlackTool('search_slack_messages', ['text' => 'reembolso', 'show_on_screen' => false])->assertJsonPath('canvas', null);
});

test('a shared image opens in the image viewer, served without exposing tokens', function () {
    runSlackTool('open_slack_file', ['file_id' => 'demo:F01'])
        ->assertJsonPath('canvas.view', 'image.viewer')
        ->assertJsonPath('canvas.layout', 'wide')
        ->assertJsonPath('canvas.props.src', '/media/chat/demo:F01')
        ->assertJsonPath('output.kind', 'image');

    $this->actingAs($this->user)->get('/media/chat/demo:F01')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('guests can not fetch media', function () {
    $this->get('/media/chat/demo:F01')->assertRedirect(route('login'));
});

test('a reply in a thread tagging someone is a draft until confirmed', function () {
    $proposal = runSlackTool('propose_slack_message', [
        'reply_to' => 'demo:C01:1001.000400',
        'text' => 'Lo reviso a las 11. @Pablo, ¿lo vemos juntos?',
        'mentions' => ['Pablo'],
    ])
        ->assertJsonPath('canvas.view', 'slack.draft')
        ->assertJsonPath('canvas.props.to', '#producto')
        ->assertJsonPath('canvas.props.mentions', ['Pablo Gil'])
        ->assertJsonPath('canvas.props.thread.ref', 'demo:C01:1001.000400')
        ->assertJsonPath('beside.view', 'slack.conversation')
        ->assertJsonPath('beside.props.thread_ref', 'demo:C01:1001.000100')
        ->assertJsonCount(4, 'beside.props.messages');

    expect($this->chats->sent)->toBe([]);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')));

    expect(PendingAction::sole()->status)->toBe(ActionStatus::Executed)
        ->and($this->chats->sent[0]->threadRef)->toBe('demo:C01:1001.000100')
        ->and($this->chats->sent[0]->mentions)->toBe([['ref' => 'demo:U02', 'name' => 'Pablo Gil']]);
});

test('a message to a person goes to their direct messages', function () {
    runSlackTool('propose_slack_message', ['to' => 'Lucía', 'text' => '¿Cerramos la migración?'])
        ->assertJsonPath('canvas.props.to', 'Lucía Romero');
});

/**
 * Two Martas, the first written to `$first` times lately and the second `$second`.
 */
function twoMartas(int $first, int $second): void
{
    app()->instance(Chats::class, new class($first, $second) extends FakeChats
    {
        public function __construct(private int $first, private int $second) {}

        public function people(User $user, string $query, int $limit = 10): array
        {
            return [
                new ChatPerson('demo:U01', 'demo@sixth-sense.test', 'Marta Ruiz', 'marta', activity: $this->first),
                new ChatPerson('demo:U09', 'demo@sixth-sense.test', 'Marta Gómez', 'mgomez', activity: $this->second),
            ];
        }
    });
}

test('ambiguous people are asked about, not guessed', function () {
    twoMartas(4, 3);

    runSlackTool('propose_slack_message', ['to' => '#producto', 'text' => 'Hola', 'mentions' => ['Marta']])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'Pregúntale cuál'));
});

test('a first name means the person the user talks to far more', function () {
    twoMartas(12, 1);

    runSlackTool('propose_slack_message', ['to' => '#producto', 'text' => 'Hola @Marta', 'mentions' => ['Marta']])
        ->assertJsonPath('canvas.props.mentions', ['Marta Ruiz']);
});

test('people and conversations can be given by the ids the assistant already knows', function () {
    runSlackTool('propose_slack_message', ['to' => 'demo:C01', 'text' => 'Hola @Marta Ruiz', 'mentions' => ['demo:U01']])
        ->assertJsonPath('canvas.props.to', '#producto')
        ->assertJsonPath('canvas.props.mentions', ['Marta Ruiz']);

    runSlackTool('propose_slack_message', ['to' => 'demo:U02', 'text' => 'Hola'])->assertJsonPath('canvas.props.to', 'Pablo Gil');
});

test('names are recognised as they are said', function (string $said, string $label) {
    runSlackTool('show_slack_conversation', ['conversation' => $said])->assertJsonPath('canvas.props.conversation.label', $label);
})->with([
    ['el canal de producto', '#producto'],
    ['Pagos', '#pagos'],
    ['Pablo Gi', 'Pablo Gil'],
    ['Marta Ruis', 'Marta Ruiz'],
]);

test('people the user talks to often are flagged when looked up', function () {
    runSlackTool('find_slack_people', ['query' => 'marta'])->assertJsonPath('output.people.0.talks_often', true);
});
