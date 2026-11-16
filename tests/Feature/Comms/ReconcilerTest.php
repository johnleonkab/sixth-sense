<?php

use App\Comms\Events\Source;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\Nightly\Reconciler;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsReconcileChange;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Carbon\CarbonImmutable;

test('an ask answered on another channel is stored as settle and left open', function () {
    [$user, $day] = nightUser();
    $marta = nightPerson($user, 'Marta');
    $nuria = nightPerson($user, 'Nuria');
    $intent = nightIntent($user, 'workstream:prepago', 'Revisar el contrato');
    nightMessage($user, $marta, '¿Me revisas el contrato?', $day->setTime(9, 0), Source::Slack, 'C1', $intent);
    $answer = nightMessage($user, $nuria, 'Ya lo cerré yo en el correo.', $day->setTime(18, 0), Source::Gmail, 'inbox', $intent);
    nightDossier($user, 'workstream:prepago', 'El contrato sigue abierto.');
    $input = nightRun($user, $day, [[
        'op' => 'settle',
        'intent_id' => (string) $intent->id,
        'reason' => 'answered_elsewhere',
        'quote' => 'Ya lo cerré yo en el correo.',
        'why' => 'Nuria lo contestó por email.',
    ]]);

    $change = CommsReconcileChange::query()->whereBelongsTo($user)->sole();

    expect($input)->toContain('DOSSIER')
        ->and(strpos($input, 'MESSAGES'))->toBeGreaterThan(strpos($input, 'DOSSIER'))
        ->and(strpos($input, 'INTENTS'))->toBeGreaterThan(strpos($input, 'MESSAGES'))
        ->and($input)->toContain('Ya lo cerré yo en el correo.')
        ->and($input)->toContain('gmail:inbox')
        ->and($change->op)->toBe('settle')
        ->and($change->quote)->toBe('Ya lo cerré yo en el correo.')
        ->and($change->disposition)->toBe('proposed')
        ->and($change->applied_event_id)->toBeNull()
        ->and($intent->refresh()->state)->toBe('open')
        ->and($answer->fresh())->not->toBeNull();
});

test('the same need opened twice is stored as a merge and both stay open', function () {
    [$user, $day] = nightUser();
    $marta = nightPerson($user, 'Marta');
    $first = nightIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $second = nightIntent($user, 'workstream:prepago', 'Revisar el contrato otra vez');
    nightMessage($user, $marta, '¿Me revisas el contrato?', $day->setTime(9, 0), Source::Slack, 'C1', $first);
    nightMessage($user, $marta, 'Otra vez: ¿me revisas el contrato?', $day->setTime(11, 0), Source::Gmail, 'inbox', $second);
    nightRun($user, $day, [[
        'op' => 'merge',
        'a' => (string) $first->id,
        'b' => (string) $second->id,
        'quote' => 'Otra vez: ¿me revisas el contrato?',
        'why' => 'Es el mismo contrato.',
    ]]);

    expect(CommsReconcileChange::query()->whereBelongsTo($user)->sole()->op)->toBe('merge')
        ->and($first->refresh()->state)->toBe('open')
        ->and($second->refresh()->state)->toBe('open');
});

test('an uncertain link contradicted later is stored as relink and stays uncertain', function () {
    [$user, $day] = nightUser();
    $marta = nightPerson($user, 'Marta');
    $wrong = nightIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $right = nightIntent($user, 'workstream:prepago', 'Enviar la factura');
    $message = nightMessage($user, $marta, 'La factura, no el contrato.', $day->setTime(15, 0), Source::Slack, 'C1', $wrong, true, 'intent:'.$right->id);
    nightRun($user, $day, [[
        'op' => 'relink',
        'message_ids' => ['m'.$message->id],
        'from_intent' => (string) $wrong->id,
        'to_intent' => (string) $right->id,
        'quote' => 'La factura, no el contrato.',
        'why' => 'El mensaje habla de la factura.',
    ]]);

    expect(CommsReconcileChange::query()->whereBelongsTo($user)->sole()->op)->toBe('relink')
        ->and(CommsConversationLink::query()->where('message_id', $message->id)->sole()->uncertain)->toBeTrue()
        ->and(CommsConversationLink::query()->where('message_id', $message->id)->sole()->intent_id)->toBe($wrong->id);
});

test('an uncertain link the rest of the day supports is stored as confirm_link and stays uncertain', function () {
    [$user, $day] = nightUser();
    $marta = nightPerson($user, 'Marta');
    $intent = nightIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $message = nightMessage($user, $marta, 'Sí, el contrato de prepago.', $day->setTime(16, 0), Source::Slack, 'C1', $intent, true, 'intent:999');
    nightRun($user, $day, [[
        'op' => 'confirm_link',
        'message_ids' => ['m'.$message->id],
        'intent_id' => (string) $intent->id,
        'quote' => 'Sí, el contrato de prepago.',
        'why' => 'El resto del día habla del contrato.',
    ]]);

    expect(CommsReconcileChange::query()->whereBelongsTo($user)->sole()->op)->toBe('confirm_link')
        ->and(CommsConversationLink::query()->where('message_id', $message->id)->sole()->uncertain)->toBeTrue();
});

/**
 * @return array{0: User, 1: CarbonImmutable}
 */
function nightUser(): array
{
    $user = User::factory()->create();
    CommsPreference::query()->create([
        'user_id' => $user->id,
        'timezone' => 'Europe/Madrid',
        'locale' => 'es',
        'work_start' => '09:00',
        'work_end' => '18:00',
        'work_days' => [1, 2, 3, 4, 5],
    ]);

    return [$user, CarbonImmutable::parse('2026-11-16', 'Europe/Madrid')];
}

function nightPerson(User $user, string $name): CommsPerson
{
    return CommsPerson::factory()->for($user)->create(['display_name' => $name]);
}

function nightIntent(User $user, string $topic, string $what): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'topic_subject' => $topic,
        'what' => $what,
        'state' => 'open',
    ]);
}

function nightMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at, Source $source, string $channel, CommsIntent $intent, bool $uncertain = false, ?string $alternative = null): CommsMessage
{
    $message = CommsMessage::factory()->create([
        'author_person_id' => $author->id,
        'text' => $text,
        'direction' => 'in',
        'source' => $source,
        'channel_ref' => $channel,
        'occurred_at' => $at,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => $at,
        ])->id,
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'uncertain' => $uncertain,
        'alternative' => $alternative === null ? null : [$alternative],
    ]);

    return $message;
}

function nightDossier(User $user, string $topic, string $document): void
{
    CommsTopicSearch::query()->create([
        'user_id' => $user->id,
        'topic_subject' => $topic,
        'document' => $document,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $changes
 */
function nightRun(User $user, CarbonImmutable $day, array $changes): string
{
    $fake = new FakeCompleter;
    $fake->script = [new ModelCompletion(['changes' => $changes], 'scripted')];
    app()->instance(CompletesTasks::class, $fake);
    app(Reconciler::class)->run($user, $day);

    return (string) ($fake->calls[0]['input'] ?? '');
}
