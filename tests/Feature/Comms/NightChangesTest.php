<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Nightly\ReconcileApplier;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsReconcileChange;
use App\Models\User;
use Carbon\CarbonImmutable;

test('cambios de la noche saves aplicar solo, and that class is what the night applies', function () {
    config(['comms.linking.reconciler' => true]);
    [$user, $day] = cambiosUser();
    $marta = cambiosPerson($user, 'Marta');
    $first = cambiosIntent($user, 'Revisar el contrato');
    $second = cambiosIntent($user, 'Revisar el contrato otra vez');
    $held = cambiosIntent($user, 'Enviar la factura');
    $message = cambiosMessage($user, $marta, 'Sí, el contrato de prepago.', $day->setTime(16, 0), $held, true);
    $merge = cambiosChange($user, $day, 'merge', 'Otra vez: ¿me revisas el contrato?', [
        'a' => (string) $first->id,
        'b' => (string) $second->id,
    ]);
    $confirm = cambiosChange($user, $day, 'confirm_link', 'Sí, el contrato de prepago.', [
        'message_ids' => ['m'.$message->id],
        'intent_id' => (string) $held->id,
    ]);

    $this->actingAs($user)->get(route('settings.context.index', ['tab' => 'changes']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/context')
            ->where('tab', 'changes')
            ->has('night_changes.lines', 2)
            ->where('night_changes.lines.0.quote', 'Otra vez: ¿me revisas el contrato?')
            ->where('night_changes.lines.0.answers', ['igual', 'no es igual', 'deshacer'])
            ->where('night_changes.classes', function (mixed $classes): bool {
                $byKey = collect($classes)->keyBy('key');

                return $byKey['merge']['on'] === false
                    && $byKey['confirm_link']['on'] === true
                    && $byKey['merge']['label'] !== '';
            }));

    $this->actingAs($user)->put(route('settings.context.changes.update'), [
        'classes' => [
            'confirm_link' => false,
            'relink_uncertain' => false,
            'settle_quoted' => false,
            'merge' => true,
        ],
    ])->assertRedirect();

    $event = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::UserConfigured)->sole();
    $policies = CommsContextFact::query()->whereBelongsTo($user)->where('kind', 'policy')->where('attribute', 'apply_alone')->where('status', 'active')->get();
    $mergePolicy = $policies->first(fn (CommsContextFact $fact): bool => ($fact->value['class'] ?? '') === 'merge');
    $confirmPolicy = $policies->first(fn (CommsContextFact $fact): bool => ($fact->value['class'] ?? '') === 'confirm_link');

    expect($event->normalized['operations'])->toHaveCount(4)
        ->and($mergePolicy)->not->toBeNull()
        ->and($mergePolicy->value['on'])->toBeTrue()
        ->and($mergePolicy->origin->value)->toBe('user')
        ->and($confirmPolicy->value['on'])->toBeFalse();

    expect(app(ReconcileApplier::class)->apply($user, $day))->toBe(1)
        ->and($merge->refresh()->disposition)->toBe('accepted')
        ->and($second->refresh()->state)->toBe('settled')
        ->and($second->settle_reason)->toBe('merged')
        ->and($confirm->refresh()->disposition)->toBe('proposed')
        ->and($held->refresh()->state)->toBe('open')
        ->and(CommsConversationLink::query()->where('message_id', $message->id)->sole()->uncertain)->toBeTrue();
});

/**
 * @return array{0: User, 1: CarbonImmutable}
 */
function cambiosUser(): array
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

function cambiosPerson(User $user, string $name): CommsPerson
{
    return CommsPerson::factory()->for($user)->create(['display_name' => $name]);
}

function cambiosIntent(User $user, string $what): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => $what,
        'state' => 'open',
    ]);
}

function cambiosMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at, CommsIntent $intent, bool $uncertain): CommsMessage
{
    $message = CommsMessage::factory()->create([
        'author_person_id' => $author->id,
        'text' => $text,
        'direction' => 'in',
        'source' => Source::Slack,
        'channel_ref' => 'C1',
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
 * @param  array<string, mixed>  $payload
 */
function cambiosChange(User $user, CarbonImmutable $day, string $op, string $quote, array $payload): CommsReconcileChange
{
    return CommsReconcileChange::query()->create([
        'user_id' => $user->id,
        'run_date' => $day->toDateString(),
        'op' => $op,
        'payload' => ['op' => $op, 'quote' => $quote, ...$payload],
        'quote' => $quote,
        'why' => 'La noche lo vio con el día entero.',
        'disposition' => 'proposed',
    ]);
}
