<?php

use App\Connections\AccountNeedsReconnection;
use App\Connections\AccountStatus;
use App\Connections\Provider;
use App\Connectors\Chat\ChatSearch;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Connectors\Slack\SlackChats;
use App\Connectors\Slack\SlackText;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Answer Slack methods by name.
 *
 * @param  array<string, array<string, mixed>|callable>  $methods
 */
function fakeSlack(array $methods): void
{
    $defaults = [
        'users.list' => ['ok' => true, 'members' => [
            ['id' => 'U1', 'name' => 'jhon', 'profile' => ['real_name' => 'John León', 'display_name' => 'jhon', 'email' => 'jhon@cm.com']],
            ['id' => 'U2', 'name' => 'marta', 'profile' => ['real_name' => 'Marta Ruiz', 'display_name' => 'marta', 'title' => 'Product Manager', 'email' => 'marta@cm.com']],
            ['id' => 'U3', 'name' => 'pablo', 'profile' => ['real_name' => 'Pablo Gil', 'display_name' => 'pablo', 'email' => 'pablo@cm.com']],
            ['id' => 'B1', 'name' => 'bot', 'is_bot' => true, 'profile' => ['real_name' => 'Bot']],
        ]],
        'users.conversations' => ['ok' => true, 'channels' => [
            ['id' => 'C1', 'name' => 'producto', 'is_channel' => true, 'topic' => ['value' => 'Producto y diseño']],
            ['id' => 'C2', 'name' => 'producto-ventas', 'is_channel' => true],
            ['id' => 'G1', 'name' => 'direccion', 'is_private' => true],
            ['id' => 'D1', 'is_im' => true, 'user' => 'U2'],
        ]],
    ];

    Http::fake(function (Request $request) use ($methods, $defaults) {
        $method = str(parse_url($request->url(), PHP_URL_PATH))->after('/api/')->toString();
        $answer = $methods[$method] ?? $defaults[$method] ?? ['ok' => false, 'error' => 'unknown_method'];

        return Http::response(is_callable($answer) ? $answer($request) : $answer);
    });
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = ConnectedAccount::factory()->for($this->user)->create([
        'provider' => Provider::Slack,
        'external_account_id' => 'T1:U1',
        'email' => 'jhon@cm.com',
        'access_token' => 'xoxp-1',
        'expires_at' => null,
        'scopes' => Provider::Slack->scopes(),
        'metadata' => ['team_id' => 'T1', 'team_name' => 'CoverManager', 'user_id' => 'U1'],
    ]);
    $this->slack = app(SlackChats::class);
    $this->a = $this->account->id;
});

test('people are found in the directory with their email, bots left out', function () {
    fakeSlack([]);

    $people = $this->slack->people($this->user, 'marta');

    expect($people)->toHaveCount(1)
        ->and($people[0]->toArray())->toMatchArray(['ref' => "{$this->a}:U2", 'name' => 'Marta Ruiz', 'title' => 'Product Manager', 'email' => 'marta@cm.com'])
        ->and($this->slack->people($this->user, 'bot'))->toBe([]);
});

test('conversations are named like in slack, exact names first', function () {
    fakeSlack([]);

    expect(array_map(fn ($c) => $c->label(), $this->slack->conversations($this->user, 'producto')))->toBe(['#producto', '#producto-ventas'])
        ->and($this->slack->conversations($this->user, 'marta')[0]->toArray())->toMatchArray(['kind' => 'dm', 'name' => 'Marta Ruiz'])
        ->and($this->slack->conversations($this->user, '', 'private')[0]->label())->toBe('#direccion');
});

test('unread are direct messages with unread counts and mentions after the last read', function () {
    fakeSlack([
        'conversations.list' => ['ok' => true, 'channels' => [['id' => 'D1', 'is_im' => true, 'user' => 'U2', 'unread_count_display' => 2], ['id' => 'D9', 'is_im' => true, 'user' => 'U3', 'unread_count_display' => 0]]],
        'conversations.history' => ['ok' => true, 'messages' => [['ts' => '1700000100.000100', 'user' => 'U2', 'text' => '¿Lo miras?']]],
        'search.messages' => ['ok' => true, 'messages' => ['matches' => [
            ['ts' => '1700000300.000000', 'user' => 'U3', 'text' => '<@U1> revisa esto', 'channel' => ['id' => 'C1', 'name' => 'producto']],
            ['ts' => '1700000050.000000', 'user' => 'U3', 'text' => '<@U1> viejo', 'channel' => ['id' => 'C1', 'name' => 'producto']],
        ]]],
        'conversations.info' => ['ok' => true, 'channel' => ['id' => 'C1', 'last_read' => '1700000200.000000']],
    ]);

    $unread = $this->slack->unread($this->user);

    expect($unread)->toHaveCount(2)
        ->and($unread[0]->reason)->toBe('mention')
        ->and($unread[0]->message->text)->toBe('@John León revisa esto')
        ->and($unread[0]->message->mentionsMe)->toBeTrue()
        ->and($unread[1]->reason)->toBe('dm')
        ->and($unread[1]->count)->toBe(2)
        ->and($unread[1]->message->conversationLabel)->toBe('Marta Ruiz');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'search.messages') && str_starts_with($request['query'], '<@U1> after:'));
});

test('a thread is shown with its replies and the files of each message', function () {
    fakeSlack(['conversations.replies' => ['ok' => true, 'messages' => [
        ['ts' => '1.1', 'thread_ts' => '1.1', 'user' => 'U2', 'text' => 'Nuevo flujo', 'reply_count' => 1, 'files' => [['id' => 'F1', 'name' => 'flujo.png', 'mimetype' => 'image/png', 'size' => 10]]],
        ['ts' => '1.2', 'thread_ts' => '1.1', 'user' => 'U3', 'text' => 'Bien, ver <https://figma.com/x|Figma> &amp; <#C2|producto-ventas>'],
    ]]]);

    $view = $this->slack->view($this->user, "{$this->a}:C1", threadRef: "{$this->a}:C1:1.1");

    expect($view->conversation->label())->toBe('#producto')
        ->and($view->messages)->toHaveCount(2)
        ->and($view->messages[0]->files[0]->ref)->toBe("{$this->a}:F1")
        ->and($view->messages[0]->files[0]->kind())->toBe('image')
        ->and($view->messages[1]->text)->toBe('Bien, ver Figma & #producto-ventas')
        ->and($view->messages[1]->threadRef)->toBe("{$this->a}:C1:1.1");
});

test('a message inside a thread is shown in its thread, highlighted', function () {
    fakeSlack(['conversations.replies' => ['ok' => true, 'messages' => [
        ['ts' => '1.1', 'thread_ts' => '1.1', 'user' => 'U2', 'text' => 'Nuevo flujo', 'reply_count' => 1],
        ['ts' => '1.2', 'thread_ts' => '1.1', 'user' => 'U3', 'text' => 'Bien'],
    ]]]);

    $view = $this->slack->view($this->user, "{$this->a}:C1", aroundRef: "{$this->a}:C1:1.2");

    expect($view->threadRef)->toBe("{$this->a}:C1:1.1")
        ->and($view->focusRef)->toBe("{$this->a}:C1:1.2")
        ->and($view->messages)->toHaveCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'conversations.history'));
});

test('a search turns people and channels into slack filters', function () {
    fakeSlack(['search.messages' => ['ok' => true, 'messages' => ['matches' => []]]]);

    $this->slack->search($this->user, new ChatSearch(text: 'prepago', from: 'Marta', in: 'producto'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'search.messages') && $request['query'] === 'prepago from:<@U2> in:<#C1>');
});

test('messages are sent as the user, in threads, with real mentions', function () {
    fakeSlack(['chat.postMessage' => ['ok' => true, 'ts' => '2.2'], 'conversations.open' => ['ok' => true, 'channel' => ['id' => 'D7']]]);

    $ref = $this->slack->send($this->user, new OutgoingChatMessage("{$this->a}:C1", '#producto', '@Marta Ruiz mira esto <ok>', [['ref' => "{$this->a}:U2", 'name' => 'Marta Ruiz']], "{$this->a}:C1:1.1"));
    $this->slack->send($this->user, new OutgoingChatMessage("{$this->a}:U3", 'Pablo Gil', 'Hola', [['ref' => "{$this->a}:U2", 'name' => 'Marta Ruiz']]));

    expect($ref)->toBe("{$this->a}:C1:2.2");
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chat.postMessage') && $request['channel'] === 'C1'
        && $request['thread_ts'] === '1.1' && $request['text'] === '<@U2> mira esto &lt;ok&gt;');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'conversations.open') && $request['users'] === 'U3');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chat.postMessage') && $request['channel'] === 'D7' && $request['text'] === '<@U2> Hola');
});

test('a revoked token marks the account for reconnection', function () {
    fakeSlack(['users.list' => ['ok' => false, 'error' => 'token_revoked']]);

    expect(fn () => $this->slack->people($this->user, 'x'))->toThrow(AccountNeedsReconnection::class);
    expect($this->account->refresh()->status)->toBe(AccountStatus::NeedsReconnection);
});

test('rate limits are waited out once', function () {
    Sleep::fake();
    Http::fakeSequence()
        ->push([], 429, ['Retry-After' => '2'])
        ->push(['ok' => true, 'members' => []]);

    expect($this->slack->people($this->user, ''))->toBe([]);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 2.0);
});

test('slack formatting becomes readable text', function () {
    expect(SlackText::readable('<@U2> y <!here>: <mailto:a@b.c|a@b.c> &lt;3', fn ($id) => $id === 'U2' ? 'Marta' : null))
        ->toBe('@Marta y @here: a@b.c <3');
});

/**
 * Two Martas, and search results where the user wrote to Marta Ruiz and in
 * #producto, and Pablo mentioned them.
 *
 * @return array<string, mixed>
 */
function slackWithActivity(): array
{
    $match = fn (string $ts, string $user, array $channel): array => ['ts' => $ts, 'user' => $user, 'text' => 'x', 'channel' => $channel];

    return [
        'users.list' => ['ok' => true, 'members' => [
            ['id' => 'U1', 'name' => 'jhon', 'profile' => ['real_name' => 'John León', 'display_name' => 'jhon']],
            ['id' => 'U4', 'name' => 'mgomez', 'profile' => ['real_name' => 'Marta Gómez', 'display_name' => 'mgomez']],
            ['id' => 'U2', 'name' => 'marta', 'profile' => ['real_name' => 'Marta Ruiz', 'display_name' => 'marta', 'title' => 'Product Manager']],
            ['id' => 'U3', 'name' => 'pablo', 'profile' => ['real_name' => 'Pablo Gil', 'display_name' => 'pablo']],
        ]],
        'search.messages' => fn (Request $request): array => ['ok' => true, 'messages' => [
            'paging' => ['pages' => 1],
            'matches' => str_ends_with($request['query'], 'before:2026-10-10') ? [
                $match('3.0', 'U1', ['id' => 'D1', 'is_im' => true]),
                $match('2.0', 'U1', ['id' => 'D1', 'is_im' => true]),
                $match('1.0', 'U1', ['id' => 'C1', 'name' => 'producto']),
            ] : (str_starts_with($request['query'], 'from:') ? [] : [
                $match('4.0', 'U3', ['id' => 'C1', 'name' => 'producto']),
            ]),
        ]],
    ];
}

test('warming learns who and where the user talks most in the last 30 days', function () {
    $this->travelTo('2026-10-08 10:00:00');
    fakeSlack(slackWithActivity());

    $this->slack->warm($this->user);
    $familiar = $this->slack->familiar($this->user);

    expect(array_map(fn ($person) => [$person->name, $person->activity], $familiar['people']))->toBe([['Marta Ruiz', 2], ['Pablo Gil', 1]])
        ->and(array_map(fn ($conversation) => $conversation->label(), $familiar['conversations']))->toBe(['#producto'])
        ->and($familiar['people'][0]->ref)->toBe("{$this->a}:U2");

    $searches = Http::recorded(fn (Request $request) => str_contains($request->url(), 'search.messages'))->map(fn (array $sent) => $sent[0]['query'])->values()->all();

    expect($searches)->toBe([
        '<@U1> after:2026-09-07',
        'from:<@U1> after:2026-09-07 before:2026-09-16',
        'from:<@U1> after:2026-09-15 before:2026-09-24',
        'from:<@U1> after:2026-09-23 before:2026-10-02',
        'from:<@U1> after:2026-10-01 before:2026-10-10',
    ]);
});

test('among people with the same name, the one the user talks to comes first', function () {
    $this->travelTo('2026-10-08 10:00:00');
    fakeSlack(slackWithActivity());
    $this->slack->warm($this->user);

    expect(array_map(fn ($person) => $person->name, $this->slack->people($this->user, 'marta')))->toBe(['Marta Ruiz', 'Marta Gómez'])
        ->and($this->slack->people($this->user, 'Marta Gómez')[0]->name)->toBe('Marta Gómez');
});

test('what is familiar is only read from cache, never from slack', function () {
    fakeSlack([]);

    expect($this->slack->familiar($this->user))->toBe(['people' => [], 'conversations' => []]);

    Http::assertNothingSent();
});

test('names are recognised as said: without accents, with typos, in any order', function (string $said, string $name) {
    fakeSlack([]);

    expect($this->slack->people($this->user, $said)[0]->name)->toBe($name);
})->with([
    ['marta', 'Marta Ruiz'],
    ['ruiz marta', 'Marta Ruiz'],
    ['Jhon', 'John León'],
    ['Pablo Gill', 'Pablo Gil'],
    ['Leon', 'John León'],
]);

test('a channel is found when said with words around its name', function () {
    fakeSlack([]);

    expect($this->slack->conversations($this->user, 'el canal de producto ventas')[0]->label())->toBe('#producto-ventas');
});

test('someone not in the cached directory makes it refresh, once', function () {
    $calls = 0;
    fakeSlack(['users.list' => function () use (&$calls): array {
        $calls++;

        return ['ok' => true, 'members' => $calls === 1 ? [] : [['id' => 'U7', 'name' => 'nuevo', 'profile' => ['real_name' => 'Nuevo Fichaje']]]];
    }]);

    expect($this->slack->people($this->user, 'nuevo'))->toBe([]);

    $this->travel(3)->minutes();

    expect($this->slack->people($this->user, 'nuevo')[0]->name)->toBe('Nuevo Fichaje')
        ->and($this->slack->people($this->user, 'nadie'))->toBe([])
        ->and($calls)->toBe(2);
});

test('when slack fails, what was last known is used', function () {
    fakeSlack([]);
    $this->slack->people($this->user, 'marta');

    $this->travel(13)->hours();
    fakeSlack(['users.list' => ['ok' => false, 'error' => 'internal_error']]);

    expect($this->slack->people($this->user, 'marta')[0]->name)->toBe('Marta Ruiz');
});

test('links keep their url even when slack shows a label instead', function () {
    expect(SlackText::links('Mira <https://docs.google.com/document/d/abc/edit?usp=drive_fs&amp;rtpof=true|Prepago v4> y <https://figma.com/x> <@U2>'))->toBe([
        ['url' => 'https://docs.google.com/document/d/abc/edit?usp=drive_fs&rtpof=true', 'label' => 'Prepago v4'],
        ['url' => 'https://figma.com/x', 'label' => null],
    ]);

    fakeSlack(['conversations.history' => ['ok' => true, 'messages' => [['ts' => '1.1', 'user' => 'U2', 'text' => 'El PRD: <https://docs.google.com/document/d/abc/edit|PRD prepago>']]]]);

    $message = $this->slack->view($this->user, "{$this->a}:C1")->messages[0];

    expect($message->text)->toBe('El PRD: PRD prepago')
        ->and($message->toAssistant('Europe/Madrid')['links'])->toBe(['https://docs.google.com/document/d/abc/edit']);
});

test('a message counts as answered once the user wrote after it, in its thread or its conversation', function (array $replies, array $history, bool $answered) {
    fakeSlack([
        'conversations.replies' => ['ok' => true, 'messages' => $replies],
        'conversations.history' => ['ok' => true, 'messages' => $history],
    ]);

    expect($this->slack->repliedAfter($this->user, "{$this->a}:D1:100.5"))->toBe($answered);
})->with([
    'in the thread' => [[['ts' => '100.5', 'user' => 'U2'], ['ts' => '101.0', 'user' => 'U1']], [], true],
    'in the conversation' => [[['ts' => '100.5', 'user' => 'U2']], [['ts' => '102.0', 'user' => 'U1']], true],
    'only the other person' => [[['ts' => '100.5', 'user' => 'U2']], [['ts' => '102.0', 'user' => 'U2']], false],
]);
