<?php

use App\Comms\Cutover\HomeFeed;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Notify\Notifier;
use App\Comms\Playbooks\Steps\Ask;
use App\Events\CommsNotificationDelivered;
use App\Events\CommsNotificationUpdated;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

afterEach(function () {
    Carbon::setTestNow();
});

test('a draft updates the open alert instead of adding a second one', function () {
    Carbon::setTestNow('2026-11-13 10:00:00');
    config(['comms.pipeline.slack' => true]);
    [$user, $intent, $event, $signal] = openIntentAlert();
    Event::fake([CommsNotificationDelivered::class, CommsNotificationUpdated::class]);

    $proposal = app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['channel' => 'C1'],
        payload: ['text' => 'Lo miro y te escribo cuando esté.'],
        intent: $intent,
        sourceEventIds: [$event->id],
    );

    $signal->refresh();

    expect(CommsNotification::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and($signal->kind)->toBe('proposal')
        ->and($signal->subject_type)->toBe('proposal')
        ->and($signal->subject_id)->toBe($proposal->id)
        ->and($signal->dismissed_at)->toBeNull();

    Event::assertDispatched(CommsNotificationUpdated::class, fn (CommsNotificationUpdated $update): bool => $update->notification->is($signal) && $update->broadcastAs() === 'inbox.item.updated');
    Event::assertNotDispatched(CommsNotificationDelivered::class);
});

test('a follow-up on the same intent updates the open alert', function () {
    Carbon::setTestNow('2026-11-13 10:00:00');
    config(['comms.pipeline.slack' => true]);
    [$user, $intent, $event, $signal] = openIntentAlert();
    $followUp = CommsEvent::factory()->for($user)->create();
    Event::fake([CommsNotificationDelivered::class, CommsNotificationUpdated::class]);

    app(Notifier::class)->store($user, 'signal', 'now', 'intent', $intent->id, 'n:'.$followUp->id.':'.$intent->key, [$followUp->id]);

    expect(CommsNotification::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and($signal->refresh()->kind)->toBe('signal')
        ->and($signal->source_event_ids)->toBe([$event->id, $followUp->id]);

    Event::assertNotDispatched(CommsNotificationDelivered::class);
});

test('a question replaces the open alert for the same intent', function () {
    Carbon::setTestNow('2026-11-13 10:00:00');
    [$user, $intent, $event, $signal] = openIntentAlert();

    app(Ask::class)->open($user, $intent, 'Falta concretar el encargo.', [], [$event->id], 'now');

    expect(CommsNotification::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and($signal->refresh()->kind)->toBe('question')
        ->and($signal->subject_type)->toBe('question');
});

test('a different action keeps its own alert', function () {
    Carbon::setTestNow('2026-11-13 10:00:00');
    [$user, $intent] = openIntentAlert();

    app(Gate::class)->submit(
        user: $user,
        class: ActionClass::SendSlack,
        target: ['channel' => 'C1'],
        payload: ['text' => 'Lo miro y te escribo cuando esté.'],
        intent: $intent,
    );
    app(Gate::class)->submit(
        user: $user,
        class: ActionClass::NotionCreatePage,
        target: ['ref' => 'notion:page'],
        payload: ['title' => 'Notas', 'text' => 'Notas'],
        intent: $intent,
    );

    expect(CommsNotification::query()->whereBelongsTo($user)->where('kind', 'proposal')->count())->toBe(2);
});

test('an intent that already has a signal and a draft shows one alert', function () {
    config(['comms.pipeline.slack' => true]);
    [$user, $intent, $event] = openIntentAlert();
    $proposal = CommsProposal::factory()->for($user)->create([
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendSlack,
        'payload' => ['text' => 'Lo miro y te escribo cuando esté.'],
    ]);
    CommsNotification::factory()->for($user)->create([
        'kind' => 'proposal',
        'urgency' => 'now',
        'subject_type' => 'proposal',
        'subject_id' => $proposal->id,
        'source_event_ids' => [$event->id],
    ]);

    $items = app(HomeFeed::class)->items($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]['state'])->toBe('ready')
        ->and($items[0]['title'])->toBe('Look at a Jira (unspecified)')
        ->and($items[0]['proposal']['summary'])->toBe('Lo miro y te escribo cuando esté.');
});

test('dismissing the draft also clears the alert it replaced', function () {
    config(['comms.pipeline.slack' => true]);
    [$user, $intent, $event, $signal] = openIntentAlert();
    $proposal = CommsProposal::factory()->for($user)->create([
        'intent_id' => $intent->id,
        'action_class' => ActionClass::SendSlack,
        'payload' => ['text' => 'Lo miro y te escribo cuando esté.'],
    ]);
    $draft = CommsNotification::factory()->for($user)->create([
        'kind' => 'proposal',
        'urgency' => 'now',
        'subject_type' => 'proposal',
        'subject_id' => $proposal->id,
        'source_event_ids' => [$event->id],
    ]);

    $this->actingAs($user)->postJson(route('comms.notifications.dismiss', $draft))->assertOk();

    expect($draft->refresh()->dismissed_at)->not->toBeNull()
        ->and($signal->refresh()->dismissed_at)->not->toBeNull()
        ->and(app(HomeFeed::class)->items($user))->toBe([]);
});

/**
 * @return array{0: User, 1: CommsIntent, 2: CommsEvent, 3: CommsNotification}
 */
function openIntentAlert(): array
{
    $user = User::factory()->create();
    $intent = CommsIntent::factory()->for($user)->create([
        'what' => 'Look at a Jira (unspecified)',
        'flags' => ['urgent' => true],
    ]);
    $event = CommsEvent::factory()->for($user)->create();
    $signal = CommsNotification::factory()->for($user)->create([
        'key' => 'n:1:'.$intent->key,
        'kind' => 'signal',
        'urgency' => 'now',
        'subject_type' => 'intent',
        'subject_id' => $intent->id,
        'source_event_ids' => [$event->id],
        'delivered_at' => now(),
    ]);

    return [$user, $intent, $event, $signal];
}
