<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Linking\LinkCertainty;
use App\Comms\Linking\LinkStage;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Illuminate\Support\Str;

test('an email follow-up with no reference stays on the same intent', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $slack = linkStageMessage($user, $author, 'Revisar el prepago', ['thread_ref' => 'slack-1', 'source' => Source::Slack]);
    $intent = linkStageIntent($user, $slack, 'Revisar el prepago');
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $author->id,
        'role' => 'requester',
    ]);
    $email = linkStageMessage($user, $author, 'Sigo con el prepago por email', ['thread_ref' => 'gmail-9', 'source' => Source::Gmail]);
    $segment = linkStageSegment($user, [$email], 'Sigo con el prepago por email');
    linkStageScript(array_fill(0, 3, linkStageExisting($intent->id, 'workstream:prepago', 'Sigo con el prepago por email')));

    $outcome = app(LinkStage::class)->run($segment);
    $row = CommsLinkDecision::query()->where('segment_id', $segment->id)->sole();
    $event = CommsEvent::query()->where('type', EventType::LinkDecided)->sole();

    expect($outcome->target)->toBe('intent:'.$intent->id)
        ->and($outcome->certainty)->toBe(LinkCertainty::Certain)
        ->and($outcome->agreement)->toBe('3of3')
        ->and($row->outcome)->toBe('topic_intent')
        ->and($row->intent_id)->toBe($intent->id)
        ->and($row->certainty)->toBe('certain')
        ->and($row->quote)->toBe('Sigo con el prepago por email')
        ->and($row->run_event_ids)->toHaveCount(3)
        ->and($row->event_id)->toBe($event->id)
        ->and($event->normalized['target'])->toBe('intent:'.$intent->id);
});

test('when every run is ambiguous the segment attaches to a and keeps b', function () {
    [$user, $segment, $first, $second] = linkStagePair();
    linkStageScript(array_fill(0, 3, [
        'decision' => 'ambiguous',
        'a' => 'intent:'.$first->id,
        'b' => 'intent:'.$second->id,
        'quote' => 'puede ser cualquiera',
        'why' => 'Both fit.',
    ]));

    $outcome = app(LinkStage::class)->run($segment);

    expect($outcome->target)->toBe('intent:'.$first->id)
        ->and($outcome->alternative)->toBe('intent:'.$second->id)
        ->and($outcome->certainty)->toBe(LinkCertainty::Certain)
        ->and(CommsLinkDecision::query()->sole()->alternative)->toBe(['intent:'.$second->id]);
});

test('three different intents attach to the one the retriever ranked first', function () {
    [$user, $author] = linkStagePeople();
    $created = [];

    foreach (['Uno', 'Dos', 'Tres'] as $index => $what) {
        $earlier = linkStageMessage($user, $author, $what, ['thread_ref' => '171.1']);
        $created[] = linkStageIntent($user, $earlier, $what);
    }

    $reply = linkStageMessage($user, $author, 'Sigo en el hilo', ['thread_ref' => '171.1']);
    $segment = linkStageSegment($user, [$reply], 'Otra frase sin parecido');
    linkStageScript([
        linkStageExisting($created[1]->id, 'workstream:hilo', 'Sigo en el hilo'),
        linkStageExisting($created[2]->id, 'workstream:hilo', 'Sigo en el hilo'),
        linkStageExisting($created[0]->id, 'workstream:hilo', 'Sigo en el hilo'),
    ]);

    $outcome = app(LinkStage::class)->run($segment);

    expect($outcome->target)->toBe('intent:'.$created[0]->id)
        ->and($outcome->certainty)->toBe(LinkCertainty::Uncertain)
        ->and($outcome->agreement)->toBe('none')
        ->and($outcome->alternative)->toBe('intent:'.$created[1]->id);
});

test('a new ask in an old thread does not reuse the old intent', function () {
    [$user, $author] = linkStagePeople();
    linkStageTopic($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    $earlier = linkStageMessage($user, $author, 'El prepago del viernes', ['thread_ref' => '171.1']);
    $old = linkStageIntent($user, $earlier, 'El prepago del viernes');
    $reply = linkStageMessage($user, $author, 'Hay que abrir un canal nuevo', ['thread_ref' => '171.1']);
    $segment = linkStageSegment($user, [$reply], 'Hay que abrir un canal nuevo', ['SPR']);
    linkStageScript(array_fill(0, 3, [
        'decision' => 'topic',
        'topic_id' => 'workstream:prepago',
        'intent' => ['decision' => 'no_existing_intent', 'intent_id' => null],
        'quote' => 'Hay que abrir un canal nuevo',
        'why' => 'Different need.',
    ]));

    $outcome = app(LinkStage::class)->run($segment);
    $row = CommsLinkDecision::query()->sole();

    expect($outcome->target)->toBe('topic:workstream:prepago')
        ->and($outcome->certainty)->toBe(LinkCertainty::Certain)
        ->and($row->outcome)->toBe('topic_new_intent')
        ->and($row->intent_id)->toBeNull()
        ->and($row->intent_id)->not->toBe($old->id);
});

test('a candidate the shuffle puts last is still chosen', function () {
    [$segment, $ids] = linkStageThread();
    $last = linkStageShuffled($ids, $segment->id, 0);
    $chosen = $last[array_key_last($last)];
    $fake = linkStageScript(array_fill(0, 3, linkStageExisting($chosen, 'workstream:hilo', 'Sigo en el hilo')));

    $outcome = app(LinkStage::class)->run($segment);
    $lines = linkStageIntentLines($fake->calls[0]['input']);

    expect($lines)->toBe($last)
        ->and($lines[array_key_last($lines)])->toBe($chosen)
        ->and($outcome->target)->toBe('intent:'.$chosen)
        ->and($outcome->agreement)->toBe('3of3')
        ->and(str_contains($fake->calls[0]['input'], "CANDIDATE TOPICS\n"))->toBeTrue()
        ->and(substr($fake->calls[0]['input'], 0, strpos($fake->calls[0]['input'], 'CANDIDATE TOPICS')))->toBe(substr($fake->calls[1]['input'], 0, strpos($fake->calls[1]['input'], 'CANDIDATE TOPICS')));
});

test('the same segment shuffles its candidates the same way the second time', function () {
    [$segment, $ids] = linkStageThread();
    $fake = linkStageScript(array_fill(0, 3, linkStageExisting($ids[0], 'workstream:hilo', 'Sigo en el hilo')));
    $stage = app(LinkStage::class);

    $first = $stage->run($segment);
    $orders = array_map(fn (array $call): array => linkStageIntentLines($call['input']), $fake->calls);
    $second = $stage->run($segment);

    expect($orders)->toBe([
        linkStageShuffled($ids, $segment->id, 0),
        linkStageShuffled($ids, $segment->id, 1),
        linkStageShuffled($ids, $segment->id, 2),
    ])
        ->and($fake->calls)->toHaveCount(3)
        ->and($second->target)->toBe($first->target)
        ->and(CommsLinkDecision::query()->count())->toBe(1)
        ->and(CommsEvent::query()->where('type', EventType::LinkDecided)->count())->toBe(1);
});

test('a vote for an intent that was not a candidate does not count', function () {
    [$user, $segment, $intent] = linkStageOne();
    linkStageScript([
        linkStageExisting($intent->id, 'workstream:prepago', 'Sigo con el prepago por email'),
        linkStageExisting(999999, 'workstream:prepago', 'Sigo con el prepago por email'),
        linkStageExisting($intent->id, 'workstream:prepago', 'Sigo con el prepago por email'),
    ]);

    $outcome = app(LinkStage::class)->run($segment);

    expect($outcome->target)->toBe('intent:'.$intent->id)
        ->and($outcome->certainty)->toBe(LinkCertainty::Uncertain)
        ->and($outcome->agreement)->toBe('2of3');
});

/**
 * @return array{0: User, 1: CommsPerson}
 */
function linkStagePeople(): array
{
    $user = User::factory()->create();

    return [$user, CommsPerson::factory()->for($user)->create()];
}

/**
 * @return array{0: CommsSegment, 1: list<int>}
 */
function linkStageThread(): array
{
    [$user, $author] = linkStagePeople();
    $ids = [];

    foreach (['Uno', 'Dos', 'Tres'] as $what) {
        $earlier = linkStageMessage($user, $author, $what, ['thread_ref' => '171.1']);
        $ids[] = linkStageIntent($user, $earlier, $what)->id;
    }

    $reply = linkStageMessage($user, $author, 'Sigo en el hilo', ['thread_ref' => '171.1']);

    return [linkStageSegment($user, [$reply], 'Otra frase sin parecido'), $ids];
}

/**
 * @return array{0: User, 1: CommsSegment, 2: CommsIntent, 3: CommsIntent}
 */
function linkStagePair(): array
{
    [$user, $author] = linkStagePeople();
    $firstMessage = linkStageMessage($user, $author, 'Primera', ['thread_ref' => '171.1']);
    $secondMessage = linkStageMessage($user, $author, 'Segunda', ['thread_ref' => '171.1']);
    $first = linkStageIntent($user, $firstMessage, 'Primera');
    $second = linkStageIntent($user, $secondMessage, 'Segunda');
    $reply = linkStageMessage($user, $author, 'puede ser cualquiera', ['thread_ref' => '171.1']);

    return [$user, linkStageSegment($user, [$reply], 'Otra frase sin parecido'), $first, $second];
}

/**
 * @return array{0: User, 1: CommsSegment, 2: CommsIntent}
 */
function linkStageOne(): array
{
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $slack = linkStageMessage($user, $author, 'Revisar el prepago', ['thread_ref' => 'slack-1']);
    $intent = linkStageIntent($user, $slack, 'Revisar el prepago');
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $author->id,
        'role' => 'requester',
    ]);
    $email = linkStageMessage($user, $author, 'Sigo con el prepago por email', ['thread_ref' => 'gmail-9', 'source' => Source::Gmail]);

    return [$user, linkStageSegment($user, [$email], 'Sigo con el prepago por email'), $intent];
}

/**
 * @param  list<int>  $ids
 * @return list<int>
 */
function linkStageShuffled(array $ids, int $segmentId, int $run): array
{
    mt_srand(crc32($segmentId.':'.$run));
    shuffle($ids);
    mt_srand();

    return $ids;
}

/**
 * @return list<int>
 */
function linkStageIntentLines(string $input): array
{
    $section = trim(substr($input, (int) strpos($input, "CANDIDATE INTENTS\n") + strlen("CANDIDATE INTENTS\n")));
    preg_match_all('/^id:(\d+)\b/m', $section, $matches);

    return array_map(intval(...), $matches[1]);
}

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function linkStageScript(array $bodies): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = array_map(fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'), $bodies);
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}

/**
 * @return array<string, mixed>
 */
function linkStageExisting(int $intentId, string $topic, string $quote): array
{
    return [
        'decision' => 'topic',
        'topic_id' => $topic,
        'intent' => ['decision' => 'existing', 'intent_id' => (string) $intentId],
        'quote' => $quote,
        'why' => 'Same need.',
    ];
}

/**
 * @param  list<CommsMessage>  $messages
 * @param  list<string>  $refs
 */
function linkStageSegment(User $user, array $messages, string $restated, array $refs = []): CommsSegment
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
function linkStageMessage(User $user, CommsPerson $author, string $text, array $extra = []): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
        'text' => $text,
        'direction' => 'in',
        'occurred_at' => now(),
        ...$extra,
    ]);
}

function linkStageIntent(User $user, CommsMessage $message, string $what): CommsIntent
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
function linkStageTopic(User $user, string $subject, string $document, array $aliases): void
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
