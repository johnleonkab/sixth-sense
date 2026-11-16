<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Jobs\ProcessCommsEvent;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\People\Relation;
use App\Comms\Playbooks\RouteRules;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsSignal;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

afterEach(function () {
    config(['comms.linking.v2' => false]);
});

test('two interleaved conversations become two intents', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/interleaved.php'));
});

test('a question whose referent is earlier in the window keeps that name and is linked', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/referent.php'));
});

test('the same ask in slack and then email stays one intent', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/email-follow-up.php'));
});

test('a follow-up that fits two intents keeps the other as the alternative', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/two-fits.php'));
});

test('a coffee invite stays a possible ask and does not start a playbook', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/coffee.php'));
});

test('a cc-only email with an ask opens an intent', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/cc-ask.php'));
});

test('a cc-only email with no ask opens nothing', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/cc-noise.php'));
});

test('three runs that disagree on the ask stay a possible ask without a playbook', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/disagree.php'));
});

test('a new ask in an old thread opens a second intent and leaves the first', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/new-ask.php'));
});

test('a promise stores the commitment, the user as responder, and the deadline text', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/commitment.php'));
});

test('a thumbs-up from the responder acknowledges the intent and leaves it open', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/acknowledge.php'));
});

test('one message with two asks opens two intents', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/two-asks.php'));
});

test('looping someone in updates the responder and keeps the intent', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/loop-in.php'));
});

test('a group ask taken by someone else settles with that reason', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/taken.php'));
});

test('an injected instruction stays content and does not become urgent', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/injection.php'));
});

test('a burst of direct messages about one need stays one window and one intent', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/burst.php'));
});

test('a signal keeps its topic and does not open an intent', function () {
    v2Replay(require base_path('tests/Fixtures/Comms/v2/signal.php'));
});

test('a message is buffered instead of matched while linking v2 is on', function () {
    config(['comms.linking.v2' => true]);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'text' => '¿Puedes revisar el contrato?',
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'type' => EventType::MessageReceived,
            'processed_at' => null,
        ])->id,
    ]);
    $fake = new FakeCompleter;
    app()->instance(CompletesTasks::class, $fake);

    ProcessCommsEvent::dispatchSync($message->event_id, $user->id);

    expect(CommsWindow::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(CommsEvent::query()->where('user_id', $user->id)->where('type', EventType::MessageFlagged)->count())->toBe(0)
        ->and(CommsIntent::query()->whereBelongsTo($user)->count())->toBe(0)
        ->and($fake->calls)->toBe([]);
});

test('a settled window is not applied while linking v2 is off', function () {
    config(['comms.linking.v2' => false]);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $message = CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'text' => 'Hay que revisar el prepago',
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
    ]);
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => [$message->id],
        'opened_at' => $message->occurred_at,
        'last_message_at' => $message->occurred_at,
        'state' => 'settled',
    ]);
    $event = CommsEvent::factory()->create([
        'user_id' => $user->id,
        'type' => EventType::WindowSettled,
        'source' => Source::Slack,
        'external_id' => 'window:'.$window->id,
        'normalized' => ['window_id' => $window->id],
        'processed_at' => null,
    ]);

    ProcessCommsEvent::dispatchSync($event->id, $user->id);

    expect(CommsSegment::query()->where('window_id', $window->id)->count())->toBe(0)
        ->and(CommsIntent::query()->whereBelongsTo($user)->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $spec
 */
function v2Replay(array $spec): void
{
    config(['comms.linking.v2' => true]);
    $user = User::factory()->create();
    $people = [
        'self' => CommsPerson::factory()->for($user)->create([
            'display_name' => 'John',
            'relation' => Relation::Self,
        ]),
    ];

    foreach ($spec['people'] ?? [] as $name => $display) {
        $people[$name] = CommsPerson::factory()->for($user)->create([
            'display_name' => $display,
            'relation' => Relation::Unknown,
        ]);
    }

    if (isset($spec['topic']) && is_array($spec['topic'])) {
        v2Topic($user, (string) $spec['topic']['subject'], (string) $spec['topic']['alias'], (string) ($spec['topic']['document'] ?? 'Dossier.'));
    }

    $priors = [];

    foreach ($spec['priors'] ?? [] as $prior) {
        $intent = CommsIntent::factory()->create([
            'user_id' => $user->id,
            'what' => $prior['what'],
            'state' => $prior['state'] ?? 'open',
            'topic_subject' => $spec['topic']['subject'] ?? null,
        ]);

        if (isset($prior['responder'])) {
            CommsIntentParticipant::query()->create([
                'user_id' => $user->id,
                'intent_id' => $intent->id,
                'person_id' => $people[$prior['responder']]->id,
                'role' => 'responder',
            ]);
        }

        $priors[$prior['name']] = $intent;
    }

    $fake = new FakeCompleter;
    app()->instance(CompletesTasks::class, $fake);

    foreach ($spec['phases'] as $phase) {
        [$event, $script] = v2Phase($user, $people, $priors, $spec, $phase);
        $fake->script = array_merge($fake->script, $script);
        ProcessCommsEvent::dispatchSync($event->id, $user->id);
    }

    v2Assert($user, $people, $priors, $spec['expect'] ?? [], $fake);
}

function v2Topic(User $user, string $subject, string $alias, string $document): void
{
    CommsTopicSearch::query()->create([
        'user_id' => $user->id,
        'topic_subject' => $subject,
        'document' => $document,
    ]);
    CommsTopicAlias::query()->create([
        'user_id' => $user->id,
        'topic_subject' => $subject,
        'alias' => $alias,
        'alias_normalized' => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($alias)))),
    ]);
}

/**
 * @param  array<string, CommsPerson>  $people
 * @param  array<string, CommsIntent>  $priors
 * @param  array<string, mixed>  $spec
 * @param  array<string, mixed>  $phase
 * @return array{0: CommsEvent, 1: list<ModelCompletion>}
 */
function v2Phase(User $user, array $people, array $priors, array $spec, array $phase): array
{
    $at = CarbonImmutable::parse($phase['at'] ?? '2026-03-12 18:00:00');
    $defaults = [
        'source' => $phase['source'] ?? 'slack',
        'channel' => $phase['channel'] ?? 'C1',
    ];
    $messages = [];
    $new = [];

    foreach ($phase['lookback'] ?? [] as $row) {
        $row = array_merge($defaults, $row);
        $messages[$row['name']] = v2Message($user, $people, $row, $at->subHours((int) $row['hours_before']));
    }

    if (isset($phase['lookback_notes']) && is_array($phase['lookback_notes'])) {
        $notes = $phase['lookback_notes'];

        for ($hour = (int) $notes['count']; $hour >= 1; $hour--) {
            $messages['note-'.$hour] = v2Message($user, $people, array_merge($defaults, [
                'author' => $notes['author'],
                'text' => 'nota '.$hour,
            ]), $at->subHours($hour));
        }
    }

    foreach ($phase['messages'] as $index => $row) {
        $row = array_merge($defaults, $row);
        $repeat = (int) ($row['repeat'] ?? 1);

        for ($copy = 0; $copy < $repeat; $copy++) {
            $name = $repeat === 1 ? (string) $row['name'] : $row['name'].'-'.($copy + 1);
            $message = v2Message($user, $people, $row, $at->addMinutes($index + $copy));
            $messages[$name] = $message;
            $new[$name] = $message;
        }
    }

    $fresh = array_values($new);
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => $defaults['source'],
        'group_ref' => $defaults['channel'],
        'kind' => str_starts_with($defaults['channel'], 'D') ? 'dm' : 'channel',
        'message_ids' => array_map(fn (CommsMessage $message): int => $message->id, $fresh),
        'opened_at' => $fresh[0]->occurred_at,
        'last_message_at' => $fresh[array_key_last($fresh)]->occurred_at,
        'state' => 'settled',
    ]);
    $event = CommsEvent::factory()->create([
        'user_id' => $user->id,
        'type' => EventType::WindowSettled,
        'source' => Source::from($defaults['source']),
        'external_id' => 'window:'.$window->id,
        'normalized' => [
            'window_id' => $window->id,
            'message_ids' => $window->message_ids,
            'source' => $window->source,
            'group_ref' => $window->group_ref,
        ],
        'occurred_at' => $window->last_message_at,
        'processed_at' => null,
    ]);
    $window->settled_event_id = $event->id;
    $window->save();

    return [$event, v2Script($user, $people, $priors, $spec, $phase, $messages, $new)];
}

/**
 * @param  array<string, CommsPerson>  $people
 * @param  array<string, mixed>  $row
 */
function v2Message(User $user, array $people, array $row, CarbonImmutable $at): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $people[$row['author']]->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
        'source' => Source::from((string) $row['source']),
        'channel_ref' => (string) $row['channel'],
        'thread_ref' => null,
        'text' => (string) $row['text'],
        'direction' => $row['direction'] ?? 'in',
        'occurred_at' => $at,
        'flags' => array_merge([
            'sensitive' => false,
            'urgent' => false,
            'noise' => false,
            'cc_only' => false,
        ], $row['flags'] ?? []),
        'external_id' => 'v2-'.Str::uuid(),
    ]);
}

/**
 * @param  array<string, CommsPerson>  $people
 * @param  array<string, CommsIntent>  $priors
 * @param  array<string, mixed>  $spec
 * @param  array<string, mixed>  $phase
 * @param  array<string, CommsMessage>  $messages
 * @param  array<string, CommsMessage>  $new
 * @return list<ModelCompletion>
 */
function v2Script(User $user, array $people, array $priors, array $spec, array $phase, array $messages, array $new): array
{
    $bodies = [];
    $segments = [];

    foreach ($phase['segments'] as $segment) {
        $picked = v2Pick($segment['messages'], $messages, $new);
        $fresh = v2Pick($segment['new'], $messages, $new);
        $segments[] = [
            'message_ids' => array_map(fn (CommsMessage $message): string => 'm'.$message->id, $picked),
            'new_message_ids' => array_map(fn (CommsMessage $message): string => 'm'.$message->id, $fresh),
            'participants' => array_values(array_unique(array_map(
                fn (CommsMessage $message): string => 'person:'.$message->author_person_id,
                $picked,
            ))),
            'restated' => $segment['restated'],
            'unresolved_refs' => [],
        ];
    }

    $bodies[] = ['segments' => $segments];

    foreach ($phase['segments'] as $segment) {
        $vote = v2Vote($user, $priors, $spec, $segment['link']);
        $bodies[] = $vote;
        $bodies[] = $vote;
        $bodies[] = $vote;

        if ($segment['link']['mode'] === 'none') {
            continue;
        }

        $runs = $segment['runs'] ?? array_fill(0, 3, $segment['operations']);

        foreach ($runs as $operations) {
            $bodies[] = [
                'operations' => array_map(
                    fn (array $operation): array => v2Operation($user, $people, $priors, $operation),
                    $operations,
                ),
            ];
        }

        $critics = 0;

        foreach ($runs[0] as $operation) {
            if (in_array($operation['op'], ['open', 'settle', 'reopen'], true)) {
                $critics++;
            }
        }

        for ($index = 0; $index < $critics; $index++) {
            $bodies[] = v2Critic($segment['critic'] ?? 'holds');
        }
    }

    return array_map(
        fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'),
        $bodies,
    );
}

/**
 * @param  array<string, mixed>  $link
 * @param  array<string, CommsIntent>  $priors
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function v2Vote(User $user, array $priors, array $spec, array $link): array
{
    $quote = (string) $link['quote'];
    $topic = (string) ($spec['topic']['subject'] ?? 'workstream:topic');

    return match ($link['mode']) {
        'none' => ['decision' => 'none', 'quote' => $quote, 'why' => 'Nothing to track.'],
        'topic_new' => [
            'decision' => 'topic',
            'topic_id' => $topic,
            'intent' => ['decision' => 'no_existing_intent', 'intent_id' => null],
            'quote' => $quote,
            'why' => 'A new ask on this topic.',
        ],
        'existing' => [
            'decision' => 'topic',
            'topic_id' => $topic,
            'intent' => ['decision' => 'existing', 'intent_id' => (string) v2IntentId($user, $priors, (string) $link['intent'])],
            'quote' => $quote,
            'why' => 'Same need.',
        ],
        'ambiguous' => [
            'decision' => 'ambiguous',
            'a' => 'intent:'.v2IntentId($user, $priors, (string) $link['a']),
            'b' => 'intent:'.v2IntentId($user, $priors, (string) $link['b']),
            'quote' => $quote,
            'why' => 'Both fit.',
        ],
        default => ['decision' => 'new_topic', 'name' => 'Nuevo', 'quote' => $quote, 'why' => 'A new topic.'],
    };
}

/**
 * @param  array<string, CommsPerson>  $people
 * @param  array<string, CommsIntent>  $priors
 * @param  array<string, mixed>  $operation
 * @return array<string, mixed>
 */
function v2Operation(User $user, array $people, array $priors, array $operation): array
{
    foreach (['requesters', 'responders', 'possible_responders'] as $field) {
        if (! isset($operation[$field]) || ! is_array($operation[$field])) {
            continue;
        }

        $operation[$field] = array_map(function (mixed $ref) use ($people): string {
            if ($ref === 'unclear') {
                return 'unclear';
            }

            return 'person:'.$people[(string) $ref]->id;
        }, $operation[$field]);
    }

    if (isset($operation['intent']) && is_string($operation['intent'])) {
        $operation['intent_id'] = (string) v2IntentId($user, $priors, $operation['intent']);
        unset($operation['intent']);
    }

    return $operation;
}

/**
 * @param  array<string, CommsIntent>  $priors
 */
function v2IntentId(User $user, array $priors, string $ref): int
{
    if ($ref === 'latest') {
        return (int) CommsIntent::query()->whereBelongsTo($user)->orderByDesc('id')->value('id');
    }

    return $priors[$ref]->id;
}

/**
 * @return array<string, mixed>
 */
function v2Critic(mixed $critic): array
{
    $holds = ['verdict' => 'holds'];
    $body = [
        'addressed_to_user' => $holds,
        'already_done' => $holds,
        'real_ask' => $holds,
    ];

    if (is_array($critic)) {
        $body[(string) $critic['question']] = [
            'verdict' => 'refuted',
            'quote' => (string) $critic['quote'],
        ];
    }

    return $body;
}

/**
 * @param  list<string>|string  $names
 * @param  array<string, CommsMessage>  $messages
 * @param  array<string, CommsMessage>  $new
 * @return list<CommsMessage>
 */
function v2Pick(array|string $names, array $messages, array $new): array
{
    if ($names === '*') {
        return array_values($new);
    }

    return array_map(fn (string $name): CommsMessage => $messages[$name], $names);
}

/**
 * @param  array<string, CommsPerson>  $people
 * @param  array<string, CommsIntent>  $priors
 * @param  array<string, mixed>  $expect
 */
function v2Assert(User $user, array $people, array $priors, array $expect, FakeCompleter $fake): void
{
    if (array_key_exists('intents', $expect)) {
        expect(CommsIntent::query()->whereBelongsTo($user)->count())->toBe($expect['intents']);
    }

    if (array_key_exists('segments', $expect)) {
        expect(CommsSegment::query()->whereBelongsTo($user)->count())->toBe($expect['segments']);
    }

    if (array_key_exists('windows', $expect)) {
        expect(CommsWindow::query()->whereBelongsTo($user)->count())->toBe($expect['windows']);
    }

    if (array_key_exists('links', $expect)) {
        expect(CommsConversationLink::query()->whereBelongsTo($user)->count())->toBe($expect['links']);
    }

    if (array_key_exists('signals', $expect)) {
        expect(CommsSignal::query()->whereBelongsTo($user)->count())->toBe($expect['signals']);
    }

    if (isset($expect['signal_topic'])) {
        expect(CommsSignal::query()->whereBelongsTo($user)->sole()->topic_subject)->toBe($expect['signal_topic']);
    }

    if (isset($expect['restated_contains'])) {
        $restated = CommsSegment::query()->whereBelongsTo($user)->pluck('restated')->implode(' ');
        expect($restated)->toContain($expect['restated_contains']);
    }

    if (array_key_exists('urgent', $expect)) {
        $urgent = CommsMessage::query()->whereBelongsTo($user)->get()->contains(
            fn (CommsMessage $message): bool => (bool) ($message->flags['urgent'] ?? false),
        );
        expect($urgent)->toBe($expect['urgent']);
    }

    if (array_key_exists('proposals', $expect)) {
        expect(CommsProposal::query()->whereBelongsTo($user)->count())->toBe($expect['proposals']);
    }

    if (array_key_exists('calls', $expect)) {
        expect($fake->calls)->toHaveCount($expect['calls']);
    }

    $intent = CommsIntent::query()->whereBelongsTo($user)->orderByDesc('id')->first();

    if (isset($expect['same_intent'])) {
        expect(CommsIntent::query()->whereBelongsTo($user)->sole()->id)->toBe($priors[$expect['same_intent']]->id);
        $intent = $priors[$expect['same_intent']]->fresh();
    }

    if (isset($expect['unchanged'])) {
        $prior = $priors[$expect['unchanged']]->fresh();
        expect($prior->what)->toBe($priors[$expect['unchanged']]->what)
            ->and($prior->state)->toBe('open');
    }

    if ($intent instanceof CommsIntent) {
        if (isset($expect['kind'])) {
            expect($intent->kind)->toBe($expect['kind']);
        }

        if (isset($expect['deadline'])) {
            expect($intent->deadline?->toDateString())->toBe($expect['deadline']);
        }

        if (isset($expect['deadline_text'])) {
            expect($intent->deadline_text)->toBe($expect['deadline_text']);
        }

        if (isset($expect['state'])) {
            expect($intent->state)->toBe($expect['state']);
        }

        if (isset($expect['settle_reason'])) {
            expect($intent->settle_reason)->toBe($expect['settle_reason']);
        }

        if (array_key_exists('possible_ask', $expect)) {
            expect($intent->possible_ask)->toBe($expect['possible_ask']);
        }

        if (! empty($expect['acknowledged'])) {
            expect($intent->acknowledged_at)->not->toBeNull()
                ->and($intent->state)->toBe('open');
        }

        if (isset($expect['responder'])) {
            expect(CommsIntentParticipant::query()
                ->where('intent_id', $intent->id)
                ->where('role', 'responder')
                ->where('person_id', $people[$expect['responder']]->id)
                ->exists())->toBeTrue();
        }
    }

    if (isset($expect['alternative_of'], $expect['alternative_intent'])) {
        $link = CommsConversationLink::query()
            ->where('intent_id', $priors[$expect['alternative_of']]->id)
            ->orderByDesc('id')
            ->first();
        expect($link?->alternative)->toContain('intent:'.$priors[$expect['alternative_intent']]->id)
            ->and($link?->uncertain)->toBe($expect['uncertain'] ?? false)
            ->and($link?->segment_id)->not->toBeNull()
            ->and($link?->link_event_id)->not->toBeNull();
    }

    if (($expect['playbooks'] ?? null) === false && $intent instanceof CommsIntent) {
        $message = CommsMessage::query()->whereBelongsTo($user)->orderByDesc('id')->first();
        expect($message)->not->toBeNull()
            ->and(app(RouteRules::class)->keys($user, $intent, $message))->toBe([])
            ->and(CommsProposal::query()->whereBelongsTo($user)->count())->toBe(0);
    }
}
