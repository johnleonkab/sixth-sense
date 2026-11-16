<?php

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Nightly\ChangeList;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsReconcileChange;
use App\Models\User;
use Carbon\CarbonImmutable;

test('the morning digest lists each change with its quote, and deshacer restores the link', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:30', 'Europe/Madrid'));
    [$user, $day] = listUser();
    $marta = listPerson($user, 'Marta');
    $wrong = listIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $right = listIntent($user, 'workstream:prepago', 'Enviar la factura');
    listRequester($wrong, $marta);
    $message = listMessage($user, $marta, 'La factura, no el contrato.', $day->setTime(15, 0), $right);
    $link = CommsConversationLink::query()->where('message_id', $message->id)->sole();
    $change = listChange($user, $day, 'relink', 'applied', 'La factura, no el contrato.', [
        'message_ids' => ['m'.$message->id],
        'from_intent' => (string) $wrong->id,
        'to_intent' => (string) $right->id,
        'prior' => ['links' => [['id' => $link->id, 'intent_id' => $wrong->id, 'uncertain' => true]]],
    ]);

    listDigest($user);

    $notice = CommsNotification::query()->whereBelongsTo($user)->where('kind', 'change_list')->sole();
    $item = app(ChangeList::class)->item($user);
    $line = $item['lines'][0];

    expect($notice->urgency)->toBe('digest')
        ->and($notice->key)->toBe('changes:2026-11-16')
        ->and($item['lines'])->toHaveCount(1)
        ->and($line['id'])->toBe($change->id)
        ->and($line['quote'])->toBe('La factura, no el contrato.')
        ->and($line['answers'])->toBe(['igual', 'no es igual', 'deshacer']);

    app(ChangeList::class)->answer($user, $change, 'deshacer');

    $restored = CommsConversationLink::query()->where('message_id', $message->id)->sole();
    $event = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::UserCorrected)->sole();
    $golden = CommsGoldenLabel::query()->whereBelongsTo($user)->where('kind', 'correction')->sole();

    expect($restored->intent_id)->toBe($wrong->id)
        ->and($restored->uncertain)->toBeTrue()
        ->and($change->refresh()->disposition)->toBe('undone')
        ->and($event->normalized['action'])->toBe('change_list')
        ->and($event->normalized['answer'])->toBe('deshacer')
        ->and($golden->label)->toBe('deshacer')
        ->and($golden->source_event_id)->toBe($event->id)
        ->and($golden->expected['text'])->toBe('La factura, no el contrato.')
        ->and($golden->topics)->toBe(['workstream:prepago'])
        ->and($golden->people)->toBe(['person:'.$marta->id]);
});

test('no es igual does not apply the proposal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:30', 'Europe/Madrid'));
    [$user, $day] = listUser();
    $marta = listPerson($user, 'Marta');
    $first = listIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $second = listIntent($user, 'workstream:prepago', 'Revisar el contrato otra vez');
    listRequester($first, $marta);
    $change = listChange($user, $day, 'merge', 'proposed', 'Otra vez: ¿me revisas el contrato?', [
        'a' => (string) $first->id,
        'b' => (string) $second->id,
    ]);

    listDigest($user);
    app(ChangeList::class)->answer($user, $change, 'no es igual');

    $event = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::UserCorrected)->sole();
    $golden = CommsGoldenLabel::query()->whereBelongsTo($user)->where('kind', 'correction')->sole();

    expect($first->refresh()->state)->toBe('open')
        ->and($second->refresh()->state)->toBe('open')
        ->and($change->refresh()->disposition)->toBe('proposed')
        ->and($change->applied_event_id)->toBeNull()
        ->and($event->normalized['answer'])->toBe('no_es_igual')
        ->and($golden->label)->toBe('no_es_igual')
        ->and($golden->source_event_id)->toBe($event->id)
        ->and(CommsEvent::query()->where('type', EventType::ReconcileDecided)->count())->toBe(0);
});

test('igual accepts a proposed merge and keeps the answer in the golden set', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:30', 'Europe/Madrid'));
    [$user, $day] = listUser();
    $marta = listPerson($user, 'Marta');
    $first = listIntent($user, 'workstream:prepago', 'Revisar el contrato');
    $second = listIntent($user, 'workstream:prepago', 'Revisar el contrato otra vez');
    listRequester($second, $marta);
    $change = listChange($user, $day, 'merge', 'proposed', 'Otra vez: ¿me revisas el contrato?', [
        'a' => (string) $first->id,
        'b' => (string) $second->id,
    ]);

    app(ChangeList::class)->answer($user, $change, 'igual');

    $event = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::UserCorrected)->sole();
    $golden = CommsGoldenLabel::query()->whereBelongsTo($user)->where('kind', 'correction')->sole();

    expect($second->refresh()->state)->toBe('settled')
        ->and($second->settle_reason)->toBe('merged')
        ->and($change->refresh()->disposition)->toBe('accepted')
        ->and($event->normalized['answer'])->toBe('igual')
        ->and($golden->label)->toBe('igual')
        ->and($golden->source_event_id)->toBe($event->id);
});

/**
 * @return array{0: User, 1: CarbonImmutable}
 */
function listUser(): array
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

function listPerson(User $user, string $name): CommsPerson
{
    return CommsPerson::factory()->for($user)->create(['display_name' => $name]);
}

function listIntent(User $user, string $topic, string $what): CommsIntent
{
    return CommsIntent::factory()->create([
        'user_id' => $user->id,
        'topic_subject' => $topic,
        'what' => $what,
        'state' => 'open',
    ]);
}

function listRequester(CommsIntent $intent, CommsPerson $person): void
{
    CommsIntentParticipant::factory()->create([
        'user_id' => $intent->user_id,
        'intent_id' => $intent->id,
        'person_id' => $person->id,
        'role' => 'requester',
    ]);
}

function listMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at, CommsIntent $intent): CommsMessage
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
        'uncertain' => false,
    ]);

    return $message;
}

/**
 * @param  array<string, mixed>  $payload
 */
function listChange(User $user, CarbonImmutable $day, string $op, string $disposition, string $quote, array $payload): CommsReconcileChange
{
    return CommsReconcileChange::query()->create([
        'user_id' => $user->id,
        'run_date' => $day->toDateString(),
        'op' => $op,
        'payload' => ['op' => $op, 'quote' => $quote, ...$payload],
        'quote' => $quote,
        'why' => 'La noche lo vio con el día entero.',
        'disposition' => $disposition,
    ]);
}

function listDigest(User $user): void
{
    app(EventLog::class)->append(
        user: $user,
        type: EventType::ClockDigest,
        source: Source::Clock,
        externalId: 'digest:2026-11-17:09:30',
        payload: null,
        normalized: [],
        causation: null,
        occurredAt: CarbonImmutable::parse('2026-11-17 09:30', 'Europe/Madrid'),
    );
}
