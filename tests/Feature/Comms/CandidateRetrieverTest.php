<?php

use App\Comms\Events\Source;
use App\Comms\Linking\CandidateRetriever;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Illuminate\Support\Str;

test('hard links come first, then the topic, then an open intent of that topic', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $earlier = retrieveMessage($user, $author, 'El prepago del viernes', ['thread_ref' => '171.1']);
    $reply = retrieveMessage($user, $author, 'Lo miro ahora', ['thread_ref' => '171.1']);
    $hard = retrieveIntent($user, $earlier, 'Otra cosa distinta');
    retrieveTopic($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    $open = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Nada que ver con esto',
        'state' => 'open',
        'topic_subject' => 'workstream:prepago',
    ]);
    CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Ya quedó',
        'state' => 'settled',
        'topic_subject' => 'workstream:prepago',
        'settled_at' => now(),
    ]);
    retrieveTopic($other, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    CommsIntent::factory()->create([
        'user_id' => $other->id,
        'what' => 'Nada que ver con esto',
        'state' => 'open',
        'topic_subject' => 'workstream:prepago',
    ]);

    $found = app(CandidateRetriever::class)->for(retrieveSegment($user, [$reply], 'Revisar el prepago del viernes', ['SPR']));

    expect($found->topics)->toBe(['workstream:prepago'])
        ->and($found->intents)->toBe([$hard->id, $open->id])
        ->and($found->ranking)->toBe([
            'intent:'.$hard->id,
            'topic:workstream:prepago',
            'intent:'.$open->id,
        ]);
});

test('only open intents of the first three topics are added', function () {
    $user = User::factory()->create();
    $words = [];
    $open = [];

    for ($index = 1; $index <= 4; $index++) {
        $word = sprintf('alias%02d', $index);
        $words[] = $word;
        $subject = sprintf('workstream:t%02d', $index);
        retrieveTopic($user, $subject, 'Otro asunto', [$word]);
        $open[$index] = CommsIntent::factory()->create([
            'user_id' => $user->id,
            'what' => 'Nada que ver',
            'state' => 'open',
            'topic_subject' => $subject,
        ]);
    }

    $found = app(CandidateRetriever::class)->for(retrieveSegment($user, [], implode(' ', $words)));

    expect($found->topics)->toBe(['workstream:t01', 'workstream:t02', 'workstream:t03', 'workstream:t04'])
        ->and($found->intents)->toBe([$open[1]->id, $open[2]->id, $open[3]->id])
        ->and($found->ranking)->toBe([
            'topic:workstream:t01',
            'topic:workstream:t02',
            'topic:workstream:t03',
            'topic:workstream:t04',
            'intent:'.$open[1]->id,
            'intent:'.$open[2]->id,
            'intent:'.$open[3]->id,
        ]);
});

test('an intent is a candidate when its ask matches the exchange', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = retrieveMessage($user, $author, 'Revisar el prepago del viernes');
    $similar = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Revisar el prepago del viernes',
        'state' => 'open',
    ]);
    CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'El calendario de marzo',
        'state' => 'open',
    ]);

    $found = app(CandidateRetriever::class)->for(retrieveSegment($user, [$message], 'Revisar el prepago del viernes'));

    expect($found->topics)->toBe([])
        ->and($found->intents)->toBe([$similar->id])
        ->and($found->ranking)->toBe(['intent:'.$similar->id]);
});

test('an intent is a candidate when its participant wrote the exchange', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = retrieveMessage($user, $author, 'y el formulario');
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Macarfi',
        'state' => 'open',
    ]);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $author->id,
        'role' => 'requester',
    ]);

    $found = app(CandidateRetriever::class)->for(retrieveSegment($user, [$message], 'Un formulario distinto'));

    expect($found->intents)->toBe([$intent->id]);
});

test('topics and intents stay within ten', function () {
    $user = User::factory()->create();
    $words = [];

    for ($index = 1; $index <= 12; $index++) {
        $word = sprintf('alias%02d', $index);
        $words[] = $word;
        retrieveTopic($user, sprintf('workstream:t%02d', $index), 'Otro asunto', [$word]);
    }

    for ($index = 1; $index <= 11; $index++) {
        CommsIntent::factory()->create([
            'user_id' => $user->id,
            'what' => 'Nada que ver '.$index,
            'state' => 'open',
            'topic_subject' => 'workstream:t01',
        ]);
    }

    $found = app(CandidateRetriever::class)->for(retrieveSegment($user, [], implode(' ', $words)));

    expect($found->topics)->toHaveCount(10)
        ->and($found->topics[0])->toBe('workstream:t01')
        ->and($found->intents)->toHaveCount(10);
});

/**
 * @param  list<CommsMessage>  $messages
 * @param  list<string>  $refs
 */
function retrieveSegment(User $user, array $messages, string $restated, array $refs = []): CommsSegment
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
        'unresolved_refs' => $refs,
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function retrieveMessage(User $user, CommsPerson $author, string $text, array $extra = []): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
        'source' => Source::Slack,
        'text' => $text,
        'direction' => 'in',
        'occurred_at' => now(),
        ...$extra,
    ]);
}

function retrieveIntent(User $user, CommsMessage $message, string $what): CommsIntent
{
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => $what,
        'state' => 'open',
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'operation' => 'attach',
    ]);

    return $intent;
}

/**
 * @param  list<string>  $aliases
 */
function retrieveTopic(User $user, string $subject, string $document, array $aliases): void
{
    CommsTopicSearch::query()->create([
        'user_id' => $user->id,
        'topic_subject' => $subject,
        'document' => $document,
    ]);

    foreach ($aliases as $alias) {
        CommsTopicAlias::query()->create([
            'user_id' => $user->id,
            'topic_subject' => $subject,
            'alias' => $alias,
            'alias_normalized' => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($alias)))),
        ]);
    }
}
