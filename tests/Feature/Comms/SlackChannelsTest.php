<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Ingestion\SlackInbound;
use App\Comms\Messages\Party;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsScope;
use App\Models\User;
use Carbon\CarbonImmutable;

test('ticking a channel ingests a message with no mention, and unticking stops the next one', function () {
    $user = User::factory()->create();
    $ana = new Party('U9', 'ana@example.com', 'Ana');
    $at = CarbonImmutable::parse('2026-11-02T09:00:00Z');

    $this->actingAs($user)->put(route('settings.context.channels'), ['slack_channel_ids' => ['C-extra']])->assertRedirect();

    $event = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::UserConfigured)->sole();

    expect($event->normalized['kind'])->toBe('scope')
        ->and($event->normalized['slack_channel_ids'])->toBe(['C-extra'])
        ->and(CommsScope::query()->whereBelongsTo($user)->sole()->slack_channel_ids)->toBe(['C-extra']);

    app(Ingestor::class)->ingest($user, new SlackInbound('T1', 'C-extra', '1.1', 'channel', $ana, [], 'Hello', $at));

    expect(CommsMessage::query()->whereBelongsTo($user)->count())->toBe(1);

    app(Replay::class)->run($user);

    expect(CommsScope::query()->whereBelongsTo($user)->sole()->slack_channel_ids)->toBe(['C-extra']);

    $this->actingAs($user)->put(route('settings.context.channels'), ['slack_channel_ids' => []])->assertRedirect();
    app(Ingestor::class)->ingest($user, new SlackInbound('T1', 'C-extra', '1.2', 'channel', $ana, [], 'Still there', $at->addMinute()));

    expect(CommsMessage::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(CommsScope::query()->whereBelongsTo($user)->sole()->slack_channel_ids)->toBe([]);
});

test('the channels tab shows members, recent messages and channels already in scope', function () {
    $this->app->instance(Chats::class, new FakeChats);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create(['user_id' => $user->id, 'processed_at' => now()])->id,
        'source' => Source::Slack,
        'channel_ref' => 'C01',
        'direction' => 'out',
        'occurred_at' => now()->subDay(),
    ]);

    $this->actingAs($user)->get(route('settings.context.index', ['tab' => 'channels']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'channels')
            ->where('channels.0', [
                'id' => 'C01',
                'name' => 'producto',
                'members' => 8,
                'messages' => 1,
                'included' => true,
                'selected' => false,
            ])
            ->where('channels.1.name', 'pagos')
            ->where('channels.1.included', false)
            ->where('channels.1.messages', 0));
});
