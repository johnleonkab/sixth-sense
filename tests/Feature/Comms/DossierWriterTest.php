<?php

use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\Topics\DossierWriter;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\User;

test('a moved deadline is named on every touched intent and a sensitive sentence stays out of the summary', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->create(['user_id' => $user->id, 'display_name' => 'Marta']);
    $secret = 'El diagnóstico de Ana es privado.';
    $event = fn (): int => CommsEvent::factory()->create([
        'user_id' => $user->id,
        'processed_at' => now(),
    ])->id;
    $move = CommsMessage::factory()->create([
        'author_person_id' => $marta->id,
        'text' => 'Adelantamos la fecha al viernes.',
        'occurred_at' => '2026-01-12 09:00:00',
        'event_id' => $event(),
    ]);
    $sensitive = CommsMessage::factory()->create([
        'author_person_id' => $marta->id,
        'text' => $secret,
        'flags' => ['sensitive' => true, 'urgent' => false, 'automated' => false, 'noise' => false, 'cc_only' => false],
        'occurred_at' => '2026-01-12 09:01:00',
        'event_id' => $event(),
    ]);
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => [$move->id, $sensitive->id],
        'opened_at' => $move->occurred_at,
        'last_message_at' => $sensitive->occurred_at,
        'state' => 'settled',
    ]);
    $segment = CommsSegment::query()->create([
        'user_id' => $user->id,
        'window_id' => $window->id,
        'message_ids' => [$move->id, $sensitive->id],
        'new_message_ids' => [$move->id, $sensitive->id],
        'participants' => ['person:'.$marta->id],
        'restated' => 'Marta pide adelantar la fecha.',
        'unresolved_refs' => [],
    ]);
    $topic = 'workstream:prepago';
    app(ContextWriter::class)->record($user, FactOrigin::Inferred, [[
        'op' => 'set',
        'kind' => 'workstream',
        'subject' => $topic,
        'attribute' => 'summary',
        'value' => ['text' => 'El prepago sigue en marzo.'],
        'audiences' => [],
        'confidence' => 0.6,
        'refs' => [],
        'status' => 'active',
    ], [
        'op' => 'set',
        'kind' => 'workstream',
        'subject' => $topic,
        'attribute' => 'state',
        'value' => ['text' => 'en curso'],
        'audiences' => [],
        'confidence' => 0.6,
        'refs' => [],
        'status' => 'active',
    ]], revision: 'dossier-prior');
    $first = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'topic_subject' => $topic,
        'what' => 'Confirmar la fecha',
    ]);
    $second = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'topic_subject' => $topic,
        'what' => 'Avisar al cliente',
    ]);
    $fake = new FakeCompleter;
    $fake->script = [new ModelCompletion([
        'summary' => 'El prepago se adelanta al viernes. '.$secret,
        'state' => 'fecha adelantada',
        'new_terms' => ['el lanzamiento'],
        'changed' => 'Marta adelantó la fecha al viernes.',
    ], 'scripted')];
    app()->instance(CompletesTasks::class, $fake);

    app(DossierWriter::class)->write($user, $topic, [$segment], [[
        'op' => 'update',
        'what' => 'Confirmar la fecha',
        'deadline' => '2026-01-16',
    ]], [$first->id, $second->id]);

    $input = $fake->calls[0]['input'] ?? '';
    $summary = CommsContextFact::query()
        ->whereBelongsTo($user)
        ->where('subject', $topic)
        ->where('attribute', 'summary')
        ->where('status', 'active')
        ->sole();
    $state = CommsContextFact::query()
        ->whereBelongsTo($user)
        ->where('subject', $topic)
        ->where('attribute', 'state')
        ->where('status', 'active')
        ->sole();
    $term = CommsContextFact::query()
        ->whereBelongsTo($user)
        ->where('kind', 'term')
        ->where('attribute', 'refers_to')
        ->where('status', 'active')
        ->sole();

    expect($fake->calls)->toHaveCount(1)
        ->and(strpos($input, 'PREVIOUS DOSSIER'))->toBeInt()
        ->and(strpos($input, 'APPLIED'))->toBeGreaterThan(strpos($input, 'PREVIOUS DOSSIER'))
        ->and(strpos($input, 'SEGMENT'))->toBeGreaterThan(strpos($input, 'APPLIED'))
        ->and($input)->toContain('El prepago sigue en marzo.')
        ->and($input)->toContain('source id="m'.$move->id.'"')
        ->and($summary->value['text'])->toContain('viernes')
        ->and($summary->value['text'])->not->toContain('diagnóstico')
        ->and($state->value['text'])->toBe('fecha adelantada')
        ->and($term->value['text'])->toBe('el lanzamiento')
        ->and($term->value['value'])->toBe($topic)
        ->and($first->refresh()->changed_summary)->toBe('Marta adelantó la fecha al viernes.')
        ->and($first->changed_at)->not->toBeNull()
        ->and($second->refresh()->changed_summary)->toBe('Marta adelantó la fecha al viernes.');
});
