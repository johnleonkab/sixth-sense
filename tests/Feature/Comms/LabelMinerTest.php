<?php

use App\Comms\Backfill\LabelMiner;
use App\Comms\Backfill\Passes;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a reply in the same channel labels the message acted', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create(['display_name' => 'Marta']);
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at);
    $reply = labelMessage($user, $marta, 'out', 'C1', $at->addDay());

    $counts = app(LabelMiner::class)->run($user);
    $label = CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole();

    expect($counts)->toBe(['acted' => 1, 'ignored' => 0])
        ->and($label->kind)->toBe('weak_label')
        ->and($label->label)->toBe('acted')
        ->and($label->evidence_event_ids)->toBe([$reply->event_id])
        ->and($label->people)->toBe(['person:'.$marta->id])
        ->and($label->source_event_id)->toBe($incoming->event_id);
});

test('a reply in the same thread on another channel labels the message acted', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at, thread: 'T9');
    $reply = labelMessage($user, $marta, 'out', 'C2', $at->addHour(), thread: 'T9');

    app(LabelMiner::class)->run($user);

    expect(CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole()->evidence_event_ids)->toBe([$reply->event_id]);
});

test('a message to the author on another channel labels it acted', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at);
    $reply = labelMessage($user, $marta, 'out', 'D9', $at->addHour(), recipients: [$marta->id]);

    app(LabelMiner::class)->run($user);

    expect(CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole()->evidence_event_ids)->toBe([$reply->event_id]);
});

test('a message with no reply inside the window is ignored', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at);
    labelMessage($user, $marta, 'out', 'C9', $at->addDays(4));
    labelMessage($user, $marta, 'out', 'C1', $at->addDays(3)->addMinute());

    app(LabelMiner::class)->run($user);
    $label = CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole();

    expect($label->label)->toBe('ignored')
        ->and($label->evidence_event_ids)->toBe([]);
});

test('a reply on the last moment of the window still counts', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at);
    $reply = labelMessage($user, $marta, 'out', 'C1', $at->addDays(3));

    app(LabelMiner::class)->run($user);

    expect(CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole()->evidence_event_ids)->toBe([$reply->event_id]);
});

test('moving the ticket a message names labels it acted', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at, text: '¿Miras PAY-1?');
    $change = CommsEvent::factory()->for($user)->create([
        'type' => EventType::SourceChanged,
        'source' => Source::Jira,
        'processed_at' => now(),
        'occurred_at' => $at->addDay(),
        'normalized' => ['kind' => 'status', 'ref' => 'jira:PAY-1', 'text' => 'PAY-1 pasó a Done'],
    ]);
    CommsEvent::factory()->for($user)->create([
        'type' => EventType::SourceChanged,
        'source' => Source::Notion,
        'processed_at' => now(),
        'occurred_at' => $at->addDay(),
        'normalized' => ['kind' => 'page_edited', 'ref' => 'notion:page', 'text' => 'Una nota'],
    ]);

    app(LabelMiner::class)->run($user);

    expect(CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole()->evidence_event_ids)->toBe([$change->id]);
});

test('a shared file that points at the message labels it acted', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at, externalId: 'slack-file-1');
    $shared = CommsEvent::factory()->for($user)->create([
        'type' => EventType::SourceChanged,
        'source' => Source::Drive,
        'processed_at' => now(),
        'occurred_at' => $at->addHour(),
        'normalized' => ['kind' => 'file_shared', 'ref' => 'slack-file-1', 'text' => 'El PDF'],
    ]);

    app(LabelMiner::class)->run($user);

    expect(CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole()->evidence_event_ids)->toBe([$shared->id]);
});

test('a created event that points at the message labels it acted', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at, externalId: 'mail-9');
    $created = CommsEvent::factory()->for($user)->create([
        'type' => EventType::SourceChanged,
        'source' => Source::Calendar,
        'processed_at' => now(),
        'occurred_at' => $at->addHour(),
        'normalized' => ['kind' => 'event_created', 'ref' => 'mail-9', 'text' => 'Reunión'],
    ]);

    app(LabelMiner::class)->run($user);

    expect(CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole()->evidence_event_ids)->toBe([$created->id]);
});

test('a bot message is not labelled', function () {
    $user = User::factory()->create();
    $bot = CommsPerson::factory()->for($user)->create(['is_bot' => true, 'display_name' => 'Slackbot']);
    labelMessage($user, $bot, 'in', 'C1', CarbonImmutable::parse('2026-11-01T10:00:00Z'));

    expect(app(LabelMiner::class)->run($user))->toBe(['acted' => 0, 'ignored' => 0])
        ->and(CommsGoldenLabel::query()->count())->toBe(0);
});

test('running again updates the label and does not add a row', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    $at = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    $incoming = labelMessage($user, $marta, 'in', 'C1', $at);

    app(LabelMiner::class)->run($user);
    $reply = labelMessage($user, $marta, 'out', 'C1', $at->addDay());
    app(Passes::class)->run($user, 'labels');

    $label = CommsGoldenLabel::query()->where('message_id', $incoming->id)->sole();

    expect($label->label)->toBe('acted')
        ->and($label->evidence_event_ids)->toBe([$reply->event_id])
        ->and(CommsGoldenLabel::query()->count())->toBe(1);
});

test('the nightly command labels one user and reports a missing user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create();
    labelMessage($user, $marta, 'in', 'C1', CarbonImmutable::parse('2026-11-01T10:00:00Z'));
    $theirs = CommsPerson::factory()->for($other)->create();
    labelMessage($other, $theirs, 'in', 'C1', CarbonImmutable::parse('2026-11-01T10:00:00Z'));

    $this->artisan('comms:labels', ['user' => $user->id])->assertSuccessful();

    expect(CommsGoldenLabel::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(CommsGoldenLabel::query()->whereBelongsTo($other)->count())->toBe(0);

    $this->artisan('comms:labels')->assertSuccessful();

    expect(CommsGoldenLabel::query()->whereBelongsTo($other)->count())->toBe(1);

    $this->artisan('comms:labels', ['user' => 999999])->assertFailed();
});

test('the label miner does not call a model', function () {
    expect(file_get_contents(app_path('Comms/Backfill/LabelMiner.php')))->not->toContain('ModelRouter');
});

function labelMessage(
    User $user,
    CommsPerson $author,
    string $direction,
    string $channel,
    CarbonImmutable $at,
    string $thread = '',
    array $recipients = [],
    string $text = 'Hola',
    string $externalId = '',
): CommsMessage {
    $event = CommsEvent::factory()->for($user)->create([
        'type' => $direction === 'out' ? EventType::MessageSent : EventType::MessageReceived,
        'source' => Source::Slack,
        'processed_at' => now(),
        'occurred_at' => $at,
    ]);

    return CommsMessage::factory()->for($user)->create([
        'event_id' => $event->id,
        'author_person_id' => $author->id,
        'direction' => $direction,
        'channel_ref' => $channel,
        'thread_ref' => $thread !== '' ? $thread : null,
        'source' => Source::Slack,
        'occurred_at' => $at,
        'text' => $text,
        'recipient_person_ids' => $recipients,
        'external_id' => $externalId !== '' ? $externalId : fake()->unique()->bothify('m-????????'),
    ]);
}
