<?php

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsUrgentRule;
use App\Models\User;

test('saving and removing an urgent rule appends the event', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.context.urgent'), ['kind' => 'person', 'ref' => 'slack:U01'])
        ->assertRedirect();
    $this->put(route('settings.context.urgent'), ['kind' => 'keyword', 'ref' => 'prepago'])
        ->assertRedirect();
    $this->put(route('settings.context.urgent'), ['kind' => 'channel', 'ref' => 'slack:C9', 'active' => false])
        ->assertRedirect();

    expect(CommsUrgentRule::query()->whereBelongsTo($user)->orderBy('id')->get(['kind', 'ref', 'active'])->toArray())
        ->toEqual([
            ['kind' => 'person', 'ref' => 'slack:U01', 'active' => true],
            ['kind' => 'keyword', 'ref' => 'prepago', 'active' => true],
            ['kind' => 'channel', 'ref' => 'slack:C9', 'active' => false],
        ]);

    $this->delete(route('settings.context.urgent.destroy'), ['kind' => 'person', 'ref' => 'slack:U01'])->assertRedirect();

    $events = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::UserConfigured)->orderBy('id')->get();

    expect(CommsUrgentRule::query()->whereBelongsTo($user)->where('kind', 'person')->exists())->toBeFalse()
        ->and($events->map(fn (CommsEvent $event): array => [
            'op' => $event->normalized['op'],
            'kind' => $event->normalized['rule']['kind'],
            'ref' => $event->normalized['rule']['ref'],
        ])->all())->toEqual([
            ['op' => 'set', 'kind' => 'person', 'ref' => 'slack:U01'],
            ['op' => 'set', 'kind' => 'keyword', 'ref' => 'prepago'],
            ['op' => 'set', 'kind' => 'channel', 'ref' => 'slack:C9'],
            ['op' => 'remove', 'kind' => 'person', 'ref' => 'slack:U01'],
        ]);
});

test('the urgent tab shows the default rule and the people and channels to pick', function () {
    $user = User::factory()->create();
    CommsPerson::factory()->for($user)->create(['display_name' => 'Marta Ruiz']);
    app()->instance(Chats::class, new FakeChats);

    $this->actingAs($user)
        ->get(route('settings.context.index', ['tab' => 'urgent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'urgent')
            ->where('urgent_rules.0.kind', 'person_weight')
            ->where('urgent_rules.0.ref', '35')
            ->where('urgent_rules.0.active', true)
            ->where('people.0.name', 'Marta Ruiz')
            ->where('channels.0.name', 'producto'));
});

test('a guest cannot change urgent rules', function () {
    $this->put(route('settings.context.urgent'), ['kind' => 'keyword', 'ref' => 'prepago'])->assertRedirect(route('login'));
});

test('the keyword preview counts messages from the last 30 days and shows three examples', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create();
    $stranger = CommsPerson::factory()->for($other)->create();

    $newest = urgentScreenMessage($user, $author, 'El prepago de mañana', now()->subDay());
    $second = urgentScreenMessage($user, $author, 'Prepago listo', now()->subDays(2));
    urgentScreenMessage($user, $author, 'La acción está lista', now()->subDays(3));
    $third = urgentScreenMessage($user, $author, 'otro prepago', now()->subDays(4));
    urgentScreenMessage($user, $author, 'el prepago de la semana pasada', now()->subDays(5));
    urgentScreenMessage($user, $author, 'prepagoextra no cuenta', now()->subDay());
    urgentScreenMessage($user, $author, 'el prepago de hace tiempo', now()->subDays(40));
    urgentScreenMessage($other, $stranger, 'el prepago de otra persona', now()->subDay());

    $this->actingAs($user)
        ->getJson(route('settings.context.urgent.preview', ['keyword' => 'prepago']))
        ->assertOk()
        ->assertJsonPath('count', 4)
        ->assertJsonPath('examples.0.id', $newest->id)
        ->assertJsonPath('examples.0.text', 'El prepago de mañana')
        ->assertJsonPath('examples.1.id', $second->id)
        ->assertJsonPath('examples.2.id', $third->id)
        ->assertJsonCount(3, 'examples');

    $this->getJson(route('settings.context.urgent.preview', ['keyword' => 'acción']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('examples.0.text', 'La acción está lista');
});

function urgentScreenMessage(User $user, CommsPerson $author, string $text, mixed $at): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create(['user_id' => $user->id, 'processed_at' => now()])->id,
        'source' => Source::Slack,
        'text' => $text,
        'occurred_at' => $at,
    ]);
}
