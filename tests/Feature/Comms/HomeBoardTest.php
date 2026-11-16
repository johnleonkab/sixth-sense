<?php

use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Comms\Surface\HomeBoard;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('an empty home has zero counters and no lists', function () {
    $board = HomeBoard::for(User::factory()->create());

    expect($board['counters'])->toBe([
        'needs_you' => 0,
        'system_working' => 0,
        'waiting_on_others' => 0,
        'done_today' => 0,
    ])->and($board['lists'])->toBe([
        'needs_you' => [],
        'system_working' => [],
        'waiting_on_others' => [],
        'done_today' => [],
    ])->and($board['possible_asks'])->toBe(0)
        ->and($board['uncertain_links'])->toBe(0)
        ->and($board['stale'])->toBe([])
        ->and($board['topics'])->toBe([]);
});

test('the home groups intents by who has the ball and keeps each fact to one query', function () {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $self = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'relation' => Relation::Self,
        'display_name' => 'Yo',
    ]);
    $approve = homeIntent($user, 'Aprobar el contrato', 40, '2026-01-16', 'workstream:prepago');
    $undated = homeIntent($user, 'Sin fecha', 40, null, 'workstream:otro');
    $responder = homeIntent($user, 'Responder a Ana', 10, '2026-01-20', 'workstream:prepago');
    $working = homeIntent($user, 'El sistema redacta', 0, null, null);
    $waiting = homeIntent($user, 'Esperando a Marta', 5, null, null);
    $watched = homeIntent($user, 'Un watcher espera', 1, null, null);
    $executed = homeIntent($user, 'Ya se envió', 2, null, null);
    $done = homeIntent($user, 'Cerrado hoy', 0, null, null, 'settled', CarbonImmutable::now()->subHour());
    $yesterday = homeIntent($user, 'Cerrado ayer', 0, null, null, 'settled', CarbonImmutable::now()->subDay());
    $possible = homeIntent($user, 'Quizá un café', 0, null, null, 'open', null, true);
    $snoozed = homeIntent($user, 'Pospuesto', 80, null, null);
    $snoozed->forceFill(['snoozed_until' => CarbonImmutable::now()->addDay()])->save();
    $stale = homeIntent($user, 'Preparar la demo', 3, null, null, 'stale');
    homeIntent($user, 'Caducado', 90, null, null, 'expired');
    $foreign = homeIntent($other, 'Ajeno', 100, null, null);
    homeRole($user, $self, $approve, 'responder');
    homeRole($user, $self, $undated, 'responder');
    homeRole($user, $self, $responder, 'responder');
    homeRole($user, $self, $working, 'responder');
    homeRole($user, $self, $waiting, 'requester');
    homeRole($user, $self, $executed, 'responder');
    homeRole($user, $self, $snoozed, 'responder');
    CommsProposal::factory()->count(2)->create([
        'user_id' => $user->id,
        'intent_id' => $approve->id,
        'status' => ProposalStatus::Pending,
    ]);
    CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $approve->id,
        'status' => ProposalStatus::Rejected,
    ]);
    CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $executed->id,
        'status' => ProposalStatus::Executed,
    ]);
    CommsQuestion::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $approve->id,
    ]);
    CommsQuestion::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $approve->id,
        'answered_event_id' => CommsEvent::factory()->create(['user_id' => $user->id])->id,
    ]);
    CommsPlaybookRun::query()->create([
        'user_id' => $user->id,
        'key' => 'run-live',
        'playbook' => 'prepare_work',
        'intent_id' => $working->id,
        'state' => 'running',
        'step' => 'draft',
        'context' => [],
    ]);
    CommsPlaybookRun::query()->create([
        'user_id' => $user->id,
        'key' => 'run-done',
        'playbook' => 'prepare_work',
        'intent_id' => $approve->id,
        'state' => 'done',
        'step' => 'sent',
        'context' => [],
    ]);
    CommsWatcher::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $watched->id,
        'state' => 'active',
    ]);
    CommsWatcher::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $watched->id,
        'state' => 'met',
    ]);
    $message = CommsMessage::factory()->create(['author_person_id' => $self->id]);
    $otherMessage = CommsMessage::factory()->create(['author_person_id' => $self->id]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $responder->id,
        'message_id' => $message->id,
        'uncertain' => true,
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $responder->id,
        'message_id' => $otherMessage->id,
        'uncertain' => false,
    ]);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $board = HomeBoard::for($user);

    $topics = collect($board['topics'])->keyBy(fn (array $topic): string => $topic['subject'] ?? 'none');
    $shown = collect($board['topics'])->flatMap(fn (array $topic): array => $topic['intent_ids'])->all();

    expect($queries)->toBeLessThan(15)
        ->and(collect($board['lists']['needs_you'])->pluck('what')->all())->toBe([
            'Aprobar el contrato',
            'Sin fecha',
            'Responder a Ana',
        ])
        ->and($board['lists']['needs_you'][0]['pending_proposals'])->toBe(2)
        ->and($board['lists']['needs_you'][0]['open_questions'])->toBe(1)
        ->and($board['lists']['needs_you'][0]['active_runs'])->toBe(0)
        ->and(collect($board['lists']['system_working'])->pluck('what')->all())->toBe(['El sistema redacta'])
        ->and(collect($board['lists']['waiting_on_others'])->pluck('what')->all())->toBe([
            'Esperando a Marta',
            'Ya se envió',
            'Un watcher espera',
        ])
        ->and($board['lists']['waiting_on_others'][1]['executed_actions'])->toBe(1)
        ->and($board['lists']['waiting_on_others'][2]['waiting_watchers'])->toBe(1)
        ->and(collect($board['lists']['done_today'])->pluck('what')->all())->toBe(['Cerrado hoy'])
        ->and($board['counters'])->toBe([
            'needs_you' => 3,
            'system_working' => 1,
            'waiting_on_others' => 3,
            'done_today' => 1,
        ])
        ->and($board['possible_asks'])->toBe(1)
        ->and($board['uncertain_links'])->toBe(1)
        ->and(collect($board['stale'])->pluck('what')->all())->toBe(['Preparar la demo'])
        ->and($board['stale'][0]['key'])->toBe($stale->key)
        ->and($topics['workstream:prepago']['intent_ids'])->toBe([$approve->id, $responder->id])
        ->and($shown)->not->toContain($snoozed->id)
        ->and($shown)->not->toContain($yesterday->id)
        ->and($shown)->not->toContain($foreign->id)
        ->and($shown)->toContain($possible->id)
        ->and($shown)->toContain($done->id);
});

function homeIntent(
    User $user,
    string $what,
    int $importance,
    ?string $deadline,
    ?string $topic,
    string $state = 'open',
    ?CarbonImmutable $settledAt = null,
    bool $possibleAsk = false,
): CommsIntent {
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => $what,
        'importance' => $importance,
        'deadline' => $deadline,
        'topic_subject' => $topic,
        'state' => $state,
        'settled_at' => $settledAt,
        'possible_ask' => $possibleAsk,
        'flags' => [],
    ]);
}

function homeRole(User $user, CommsPerson $person, CommsIntent $intent, string $role): void
{
    CommsIntentParticipant::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $person->id,
        'role' => $role,
    ]);
}
