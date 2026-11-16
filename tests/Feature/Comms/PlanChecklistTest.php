<?php

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Surface\PlanChecklist;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('steps that share a wait are one group, and a condition starts the sentence', function () {
    $user = User::factory()->create();
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Cerrar el lanzamiento',
        'steps' => [
            ['id' => 'a', 'what' => 'crear el evento', 'after' => [], 'condition' => 'Si Marta confirma', 'channel' => 'calendar', 'preview' => 'Jueves 10:00'],
            ['id' => 'b', 'what' => 'avisar en Slack', 'after' => [], 'channel' => 'slack', 'preview' => 'Quedamos el jueves'],
            ['id' => 'c', 'what' => 'enviar el resumen', 'after' => ['b', 'a'], 'status' => 'blocked'],
        ],
    ]);

    $plan = app(PlanChecklist::class)->for($intent);

    expect($plan)->toHaveCount(2)
        ->and($plan[0]['after'])->toBe([])
        ->and(collect($plan[0]['steps'])->pluck('id')->all())->toBe(['a', 'b'])
        ->and($plan[0]['steps'][0]['text'])->toBe('Si Marta confirma: crear el evento')
        ->and($plan[0]['steps'][0]['status'])->toBe('waiting')
        ->and($plan[0]['steps'][0]['channel'])->toBe('calendar')
        ->and($plan[0]['steps'][0]['preview'])->toBe('Jueves 10:00')
        ->and($plan[0]['steps'][1]['channel'])->toBe('slack')
        ->and($plan[0]['steps'][1]['preview'])->toBe('Quedamos el jueves')
        ->and($plan[1]['after'])->toBe(['a', 'b'])
        ->and($plan[1]['steps'])->toHaveCount(1)
        ->and($plan[1]['steps'][0]['status'])->toBe('blocked')
        ->and($plan[1]['steps'][0]['text'])->toBe('enviar el resumen');
});

test('a proposal, a run, and a watcher become steps with who they wait on', function () {
    CarbonImmutable::setTestNow('2026-01-15 10:00:00');
    $user = User::factory()->create();
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Avisar a Marta',
        'steps' => [],
    ]);
    CommsPlaybookRun::query()->create([
        'user_id' => $user->id,
        'key' => 'run-live',
        'playbook' => 'prepare_work',
        'intent_id' => $intent->id,
        'state' => 'running',
        'step' => 'draft',
        'context' => ['what' => 'Redactar el aviso', 'channel' => 'slack', 'preview' => 'Hola Marta'],
    ]);
    CommsPlaybookRun::query()->create([
        'user_id' => $user->id,
        'key' => 'run-done',
        'playbook' => 'prepare_work',
        'intent_id' => $intent->id,
        'state' => 'done',
        'step' => 'sent',
        'context' => ['what' => 'Enviado'],
    ]);
    CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendSlack,
        'status' => ProposalStatus::Pending,
        'payload' => ['text' => '¿Te va el jueves?'],
    ]);
    CommsProposal::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendEmailReply,
        'status' => ProposalStatus::Rejected,
        'payload' => ['text' => 'No se envía'],
    ]);
    $watcher = CommsWatcher::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'state' => 'active',
        'condition' => ['person' => 'Marta', 'expects' => 'crear el evento'],
    ]);

    $steps = app(PlanChecklist::class)->for($intent)[0]['steps'];

    expect(collect($steps)->pluck('status')->all())->toBe(['running', 'done', 'needs_approval', 'skipped', 'waiting'])
        ->and($steps[0]['channel'])->toBe('slack')
        ->and($steps[0]['preview'])->toBe('Hola Marta')
        ->and($steps[2]['channel'])->toBe('slack')
        ->and($steps[2]['preview'])->toBe('¿Te va el jueves?')
        ->and($steps[3]['channel'])->toBe('gmail')
        ->and($steps[4]['text'])->toBe('Si Marta confirma: crear el evento')
        ->and($steps[4]['waiting_on']['name'])->toBe('Marta')
        ->and($steps[4]['waiting_on']['since'])->toBe($watcher->fresh()->created_at->toIso8601String());
});
