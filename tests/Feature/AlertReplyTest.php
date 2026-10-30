<?php

use App\Actions\ActionStatus;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\InboxItem;
use App\Models\PendingAction;
use App\Models\User;

beforeEach(function () {
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->user = User::factory()->create();
    $this->alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack,
        'external_id' => 'demo:C01:1001.000400',
        'proposal' => ['summary' => 'Confirmarle que lo revisas.', 'reply' => ['to' => 'thread', 'text' => 'Lo reviso y te confirmo.']],
    ]);
});

test('one tap sends the proposed Slack reply in its thread, after the undo window', function () {
    $this->actingAs($this->user)
        ->postJson(route('alerts.reply', $this->alert), ['timezone' => 'Europe/Madrid'])
        ->assertOk()
        ->assertJsonPath('action.type', 'chat.send');

    expect(PendingAction::sole())
        ->status->toBe(ActionStatus::Executed)
        ->undo_until->not->toBeNull()
        ->and($this->chats->sent[0]->text)->toBe('Lo reviso y te confirmo.')
        ->and($this->chats->sent[0]->threadRef)->toBe('demo:C01:1001.000100');
});

test('only a pending Slack alert of the person, with a reply proposed, can be sent', function () {
    $this->alert->update(['status' => AlertStatus::Resolved]);
    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert))->assertUnprocessable();

    $other = InboxItem::factory()->create(['source' => AlertSource::Slack, 'proposal' => ['reply' => ['to' => 'thread', 'text' => 'Hola']]]);
    $this->actingAs($this->user)->postJson(route('alerts.reply', $other))->assertNotFound();

    expect($this->chats->sent)->toBe([]);
});
