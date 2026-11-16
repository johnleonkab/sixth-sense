<?php

use App\Comms\Events\Source;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\Nightly\ReconcileApplier;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsReconcileChange;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a later message that shows an uncertain link was wrong moves it, and undo puts it back', function () {
    config(['comms.linking.reconciler' => true]);
    [$user, $day] = applierUser();
    $marta = applierPerson($user, 'Marta');
    $wrong = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $right = applierIntent($user, 'workstream:prepago', 'Enviar la factura');
    $supported = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $contradicted = applierMessage($user, $marta, 'La factura, no el contrato.', $day->setTime(15, 0), Source::Slack, 'C1', $wrong, true);
    $held = applierMessage($user, $marta, 'Sí, el contrato de prepago.', $day->setTime(16, 0), Source::Slack, 'C1', $supported, true);
    applierScript([new ModelCompletion(['changes' => [
        [
            'op' => 'relink',
            'message_ids' => ['m'.$contradicted->id],
            'from_intent' => (string) $wrong->id,
            'to_intent' => (string) $right->id,
            'quote' => 'La factura, no el contrato.',
            'why' => 'El mensaje habla de la factura.',
        ],
        [
            'op' => 'confirm_link',
            'message_ids' => ['m'.$held->id],
            'intent_id' => (string) $supported->id,
            'quote' => 'Sí, el contrato de prepago.',
            'why' => 'El resto del día habla del contrato.',
        ],
    ]], 'scripted')]);

    $this->artisan('comms:reconcile-nightly', ['user' => $user->id, '--date' => '2026-11-16'])->assertSuccessful();

    $moved = CommsConversationLink::query()->where('message_id', $contradicted->id)->sole();
    $confirmed = CommsConversationLink::query()->where('message_id', $held->id)->sole();
    $relink = CommsReconcileChange::query()->whereBelongsTo($user)->where('op', 'relink')->sole();
    $confirm = CommsReconcileChange::query()->whereBelongsTo($user)->where('op', 'confirm_link')->sole();

    expect($moved->intent_id)->toBe($right->id)
        ->and($moved->uncertain)->toBeFalse()
        ->and($confirmed->intent_id)->toBe($supported->id)
        ->and($confirmed->uncertain)->toBeFalse()
        ->and($relink->disposition)->toBe('applied')
        ->and($relink->quote)->toBe('La factura, no el contrato.')
        ->and($relink->applied_event_id)->not->toBeNull()
        ->and($confirm->disposition)->toBe('applied')
        ->and($confirm->quote)->toBe('Sí, el contrato de prepago.');

    expect(app(ReconcileApplier::class)->undo($relink->refresh()))->toBeTrue();

    $restored = CommsConversationLink::query()->where('message_id', $contradicted->id)->sole();

    expect($restored->intent_id)->toBe($wrong->id)
        ->and($restored->uncertain)->toBeTrue()
        ->and($relink->refresh()->disposition)->toBe('undone')
        ->and($confirmed->refresh()->uncertain)->toBeFalse();
});

test('an ask answered on another channel by a third person is settled and kept in the list', function () {
    config(['comms.linking.reconciler' => true]);
    [$user, $day] = applierUser();
    $nuria = applierPerson($user, 'Nuria');
    $intent = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    applierMessage($user, $nuria, 'Ya lo cerré yo en el correo.', $day->setTime(18, 0), Source::Gmail, 'inbox', $intent);
    $fake = applierScript([
        new ModelCompletion(['changes' => [[
            'op' => 'settle',
            'intent_id' => (string) $intent->id,
            'reason' => 'answered_elsewhere',
            'quote' => 'Ya lo cerré yo en el correo.',
            'why' => 'Nuria lo contestó por email.',
        ]]], 'scripted'),
        new ModelCompletion(applierHolds(), 'scripted'),
    ]);

    $this->artisan('comms:reconcile-nightly', ['user' => $user->id, '--date' => '2026-11-16'])->assertSuccessful();

    $change = CommsReconcileChange::query()->whereBelongsTo($user)->sole();

    expect($intent->refresh()->state)->toBe('settled')
        ->and($intent->settle_reason)->toBe('taken_by_other')
        ->and($change->op)->toBe('settle')
        ->and($change->disposition)->toBe('applied')
        ->and($change->quote)->toBe('Ya lo cerré yo en el correo.')
        ->and($fake->calls)->toHaveCount(2)
        ->and($fake->calls[1]['input'])->toContain('Ya lo cerré yo en el correo.');
});

test('the same need opened twice is proposed, and applied only when it is accepted', function () {
    config(['comms.linking.reconciler' => true]);
    [$user, $day] = applierUser();
    $marta = applierPerson($user, 'Marta');
    $first = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $second = applierIntent($user, 'workstream:prepago', 'Revisar el contrato otra vez');
    applierMessage($user, $marta, '¿Me revisas el contrato?', $day->setTime(9, 0), Source::Slack, 'C1', $first);
    $again = applierMessage($user, $marta, 'Otra vez: ¿me revisas el contrato?', $day->setTime(11, 0), Source::Gmail, 'inbox', $second);
    applierScript([new ModelCompletion(['changes' => [[
        'op' => 'merge',
        'a' => (string) $first->id,
        'b' => (string) $second->id,
        'quote' => 'Otra vez: ¿me revisas el contrato?',
        'why' => 'Es el mismo contrato.',
    ]]], 'scripted')]);

    $this->artisan('comms:reconcile-nightly', ['user' => $user->id, '--date' => '2026-11-16'])->assertSuccessful();

    $change = CommsReconcileChange::query()->whereBelongsTo($user)->sole();

    expect($change->op)->toBe('merge')
        ->and($change->disposition)->toBe('proposed')
        ->and($change->quote)->toBe('Otra vez: ¿me revisas el contrato?')
        ->and($first->refresh()->state)->toBe('open')
        ->and($second->refresh()->state)->toBe('open');

    expect(app(ReconcileApplier::class)->accept($change))->toBeTrue()
        ->and($first->refresh()->state)->toBe('open')
        ->and($second->refresh()->state)->toBe('settled')
        ->and($second->settle_reason)->toBe('merged')
        ->and(CommsConversationLink::query()->where('message_id', $again->id)->sole()->intent_id)->toBe($first->id)
        ->and($change->refresh()->disposition)->toBe('accepted');
});

test('the night leaves a stored relink untouched while the reconciler flag is off', function () {
    [$user, $day] = applierUser();
    $marta = applierPerson($user, 'Marta');
    $wrong = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $right = applierIntent($user, 'workstream:prepago', 'Enviar la factura');
    $message = applierMessage($user, $marta, 'La factura, no el contrato.', $day->setTime(15, 0), Source::Slack, 'C1', $wrong, true);
    $change = applierChange($user, $day, [
        'op' => 'relink',
        'message_ids' => ['m'.$message->id],
        'from_intent' => (string) $wrong->id,
        'to_intent' => (string) $right->id,
        'quote' => 'La factura, no el contrato.',
    ]);

    expect(app(ReconcileApplier::class)->apply($user, $day))->toBe(0)
        ->and($change->refresh()->disposition)->toBe('proposed')
        ->and(CommsConversationLink::query()->where('message_id', $message->id)->sole()->intent_id)->toBe($wrong->id)
        ->and(CommsConversationLink::query()->where('message_id', $message->id)->sole()->uncertain)->toBeTrue();
});

test('a relink of a certain link stays proposed', function () {
    config(['comms.linking.reconciler' => true]);
    [$user, $day] = applierUser();
    $marta = applierPerson($user, 'Marta');
    $from = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $to = applierIntent($user, 'workstream:prepago', 'Enviar la factura');
    $message = applierMessage($user, $marta, 'La factura, no el contrato.', $day->setTime(15, 0), Source::Slack, 'C1', $from);
    $change = applierChange($user, $day, [
        'op' => 'relink',
        'message_ids' => ['m'.$message->id],
        'from_intent' => (string) $from->id,
        'to_intent' => (string) $to->id,
        'quote' => 'La factura, no el contrato.',
    ]);

    expect(app(ReconcileApplier::class)->apply($user, $day))->toBe(0)
        ->and($change->refresh()->disposition)->toBe('proposed')
        ->and(CommsConversationLink::query()->where('message_id', $message->id)->sole()->intent_id)->toBe($from->id);
});

test('a settle the critic refutes stays open and proposed', function () {
    config(['comms.linking.reconciler' => true]);
    [$user, $day] = applierUser();
    $nuria = applierPerson($user, 'Nuria');
    $intent = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    applierMessage($user, $nuria, 'Ya lo cerré yo en el correo.', $day->setTime(18, 0), Source::Gmail, 'inbox', $intent);
    $change = applierChange($user, $day, [
        'op' => 'settle',
        'intent_id' => (string) $intent->id,
        'reason' => 'answered_elsewhere',
        'quote' => 'Ya lo cerré yo en el correo.',
    ]);
    applierScript([new ModelCompletion([
        'addressed_to_user' => ['verdict' => 'holds'],
        'already_done' => ['verdict' => 'holds'],
        'real_ask' => ['verdict' => 'refuted', 'quote' => 'Ya lo cerré yo en el correo.'],
    ], 'scripted')]);

    expect(app(ReconcileApplier::class)->apply($user, $day))->toBe(0)
        ->and($intent->refresh()->state)->toBe('open')
        ->and($change->refresh()->disposition)->toBe('proposed')
        ->and($change->applied_event_id)->toBeNull();
});

test('a settle whose quote is not in the day is not applied and does not ask the critic', function () {
    config(['comms.linking.reconciler' => true]);
    [$user, $day] = applierUser();
    $nuria = applierPerson($user, 'Nuria');
    $intent = applierIntent($user, 'workstream:prepago', 'Revisar el contrato');
    applierMessage($user, $nuria, 'Ya lo cerré yo en el correo.', $day->setTime(18, 0), Source::Gmail, 'inbox', $intent);
    $change = applierChange($user, $day, [
        'op' => 'settle',
        'intent_id' => (string) $intent->id,
        'reason' => 'answered_elsewhere',
        'quote' => 'Esto no está en ningún mensaje.',
    ]);
    $fake = applierScript([]);

    expect(app(ReconcileApplier::class)->apply($user, $day))->toBe(0)
        ->and($intent->refresh()->state)->toBe('open')
        ->and($change->refresh()->disposition)->toBe('proposed')
        ->and($fake->calls)->toBe([]);
});

/**
 * @return array{0: User, 1: CarbonImmutable}
 */
function applierUser(): array
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

function applierPerson(User $user, string $name): CommsPerson
{
    return CommsPerson::factory()->for($user)->create(['display_name' => $name]);
}

function applierIntent(User $user, string $topic, string $what): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'topic_subject' => $topic,
        'what' => $what,
        'state' => 'open',
    ]);
}

function applierMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at, Source $source, string $channel, CommsIntent $intent, bool $uncertain = false): CommsMessage
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
    ]);

    return $message;
}

/**
 * @param  array<string, mixed>  $change
 */
function applierChange(User $user, CarbonImmutable $day, array $change): CommsReconcileChange
{
    return CommsReconcileChange::query()->create([
        'user_id' => $user->id,
        'run_date' => $day->toDateString(),
        'op' => $change['op'],
        'payload' => $change,
        'quote' => is_string($change['quote'] ?? null) ? $change['quote'] : null,
        'why' => is_string($change['why'] ?? null) ? $change['why'] : null,
        'disposition' => 'proposed',
    ]);
}

/**
 * @param  list<ModelCompletion>  $completions
 */
function applierScript(array $completions): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = $completions;
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}

/**
 * @return array<string, array<string, string>>
 */
function applierHolds(): array
{
    return [
        'addressed_to_user' => ['verdict' => 'holds'],
        'already_done' => ['verdict' => 'holds'],
        'real_ask' => ['verdict' => 'holds'],
    ];
}
