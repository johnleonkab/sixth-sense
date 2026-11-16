<?php

use App\Comms\Events\Source;
use App\Comms\Linking\HardLinks;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\User;

test('a thread already linked to an intent is a hard link', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $earlier = hardLinkMessage($user, $author, 'El prepago del viernes', ['thread_ref' => '171.1']);
    $reply = hardLinkMessage($user, $author, 'Lo miro ahora', ['thread_ref' => '171.1']);
    $otherThread = hardLinkMessage($user, $author, 'Otra cosa', ['thread_ref' => '171.9']);
    $first = hardLinkIntent($user, $earlier);
    $second = hardLinkIntent($user, $earlier);
    hardLinkIntent($user, $otherThread);
    $stranger = User::factory()->create();
    $strangerAuthor = CommsPerson::factory()->for($stranger)->create();
    hardLinkIntent($stranger, hardLinkMessage($stranger, $strangerAuthor, 'Ajeno', ['thread_ref' => '171.1']));

    $keys = app(HardLinks::class)->for(hardLinkSegment($user, [$reply]));

    expect($keys)->toBe(['intent:'.$first->id, 'intent:'.$second->id]);
});

test('a jira key in the text points at the intent that already mentioned it', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $known = hardLinkMessage($user, $author, 'Seguimos PAY-41 y no PAY-410');
    $decoy = hardLinkMessage($user, $author, 'Eso es PAY-410');
    $intent = hardLinkIntent($user, $known);
    hardLinkIntent($user, $decoy);
    $fresh = hardLinkMessage($user, $author, '¿Puedes ver https://jira.example/browse/PAY-41?');

    $keys = app(HardLinks::class)->for(hardLinkSegment($user, [$fresh], 'Revisar PAY-41'));

    expect($keys)->toBe(['intent:'.$intent->id]);
});

test('a notion page, a pull request and a url point at the intents that already had them', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $page = '0123456789abcdef0123456789abcdef';
    $notion = hardLinkIntent($user, hardLinkMessage($user, $author, 'La página '.$page));
    $pull = hardLinkIntent($user, hardLinkMessage($user, $author, 'Quedó en pull/418'));
    $url = hardLinkIntent($user, hardLinkMessage($user, $author, 'El doc https://docs.example.com/prepago.pdf'));
    $fresh = hardLinkMessage($user, $author, 'Mira https://notion.so/'.$page.' y https://github.com/acme/app/pull/418', [
        'links' => ['https://docs.example.com/prepago.pdf'],
    ]);

    $keys = app(HardLinks::class)->for(hardLinkSegment($user, [$fresh]));

    expect($keys)->toBe(['intent:'.$notion->id, 'intent:'.$pull->id, 'intent:'.$url->id]);
});

test('an email reply header points at the intent of the message it answers', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $parent = hardLinkIntent($user, hardLinkMessage($user, $author, 'El prepago', [
        'source' => Source::Gmail,
        'thread_ref' => 'gmail-1',
        'headers' => ['message-id' => '<Old@mail.example>'],
    ]));
    $older = hardLinkIntent($user, hardLinkMessage($user, $author, 'El hilo', [
        'source' => Source::Gmail,
        'thread_ref' => 'gmail-0',
        'headers' => ['message-id' => '<older@mail.example>'],
    ]));
    $reply = hardLinkMessage($user, $author, 'Sigo con esto', [
        'source' => Source::Gmail,
        'thread_ref' => 'gmail-2',
        'headers' => [
            'in-reply-to' => '<old@mail.example>',
            'references' => '<older@mail.example> <old@mail.example>',
        ],
    ]);
    $stranger = User::factory()->create();
    $strangerAuthor = CommsPerson::factory()->for($stranger)->create();
    hardLinkIntent($stranger, hardLinkMessage($stranger, $strangerAuthor, 'Ajeno', [
        'source' => Source::Gmail,
        'headers' => ['message-id' => '<old@mail.example>'],
    ]));

    $keys = app(HardLinks::class)->for(hardLinkSegment($user, [$reply]));

    expect($keys)->toBe(['intent:'.$parent->id, 'intent:'.$older->id]);
});

test('nothing already linked means no hard link', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = hardLinkMessage($user, $author, 'PAY-41 suelto', ['thread_ref' => '171.1']);

    expect(app(HardLinks::class)->for(hardLinkSegment($user, [$message])))->toBe([])
        ->and(app(HardLinks::class)->for(hardLinkSegment($user, [])))->toBe([]);
});

/**
 * @param  list<CommsMessage>  $messages
 */
function hardLinkSegment(User $user, array $messages, string $restated = 'El segmento'): CommsSegment
{
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $messages),
        'opened_at' => now(),
        'last_message_at' => now(),
        'state' => 'settled',
    ]);

    return CommsSegment::query()->create([
        'user_id' => $user->id,
        'window_id' => $window->id,
        'message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $messages),
        'new_message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $messages),
        'participants' => [],
        'restated' => $restated,
        'unresolved_refs' => [],
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function hardLinkMessage(User $user, CommsPerson $author, string $text, array $extra = []): CommsMessage
{
    $headers = $extra['headers'] ?? null;
    unset($extra['headers']);

    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
            'normalized' => is_array($headers) ? ['headers' => $headers] : ['text' => $text],
        ])->id,
        'text' => $text,
        'direction' => 'in',
        'occurred_at' => now(),
        ...$extra,
    ]);
}

function hardLinkIntent(User $user, CommsMessage $message): CommsIntent
{
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Un asunto',
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'operation' => 'attach',
    ]);

    return $intent;
}
