<?php

use App\Alerts\AlertSource;
use App\Models\InboxItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('para mí lists the person avisos with filters and a pending count', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    InboxItem::factory()->for($user)->create([
        'source' => AlertSource::Slack,
        'title' => 'Mensaje directo',
        'why' => 'Mensaje directo de Marta Ruiz.',
        'actor_name' => 'Marta Ruiz',
        'body' => '¿Puedes revisar el prepago?',
        'read_at' => null,
    ]);
    InboxItem::factory()->for($user)->create([
        'source' => AlertSource::Gmail,
        'title' => 'Contrato',
        'why' => 'Andrés te pide algo.',
        'read_at' => now(),
    ]);
    InboxItem::factory()->for($user)->create([
        'source' => AlertSource::Jira,
        'status' => 'resolved',
        'title' => 'Ya resuelto',
    ]);
    InboxItem::factory()->for($other)->create([
        'source' => AlertSource::Slack,
        'title' => 'De otra persona',
    ]);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('forMe.pending_count', 1)
            ->where('forMe.pending_label', '1 pendiente')
            ->where('forMe.filters.0.key', 'all')
            ->where('forMe.filters.1.key', 'slack')
            ->where('forMe.items', function (mixed $items): bool {
                $rows = collect(is_array($items) ? $items : $items->all());

                return $rows->count() === 2
                    && $rows->pluck('title')->contains('Mensaje directo')
                    && $rows->contains(fn (array $row): bool => $row['title'] === 'Mensaje directo' && $row['index'] >= 1 && $row['why'] === 'Mensaje directo de Marta Ruiz.')
                    && ! $rows->pluck('title')->contains('Ya resuelto')
                    && ! $rows->pluck('title')->contains('De otra persona');
            }));

    $this->actingAs($user)->get(route('home', ['source' => 'slack']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('forMe.filter', 'slack')
            ->where('forMe.pending_count', 1)
            ->where('forMe.items', fn (mixed $items): bool => count(is_array($items) ? $items : $items->all()) === 1));
});

test('opening an aviso marks it read and another person cannot', function () {
    $user = User::factory()->create();
    $item = InboxItem::factory()->for($user)->create(['source' => AlertSource::Notion, 'read_at' => null]);

    $this->actingAs(User::factory()->create())
        ->post(route('for-me.read', $item))
        ->assertNotFound();

    expect($item->refresh()->read_at)->toBeNull();

    $this->actingAs($user)->post(route('for-me.read', $item))->assertRedirect();

    expect($item->refresh()->read_at)->not->toBeNull();

    InboxItem::factory()->for($user)->create(['source' => AlertSource::Gmail, 'read_at' => null]);
    $this->actingAs($user)->post(route('for-me.read-all'))->assertRedirect();

    expect($user->inboxItems()->whereNull('read_at')->count())->toBe(0);
});

test('two pending avisos are counted as pendientes', function () {
    $user = User::factory()->create();
    InboxItem::factory()->for($user)->count(2)->create(['source' => AlertSource::Slack, 'read_at' => null]);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('forMe.pending_label', '2 pendientes'));
});
