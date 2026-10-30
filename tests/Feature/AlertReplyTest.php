<?php

use App\Actions\ActionStatus;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\Outbox;
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
        ->assertJsonPath('actions.0.type', 'chat.send');

    expect(PendingAction::sole())
        ->status->toBe(ActionStatus::Executed)
        ->undo_until->not->toBeNull()
        ->and($this->chats->sent[0]->text)->toBe('Lo reviso y te confirmo.')
        ->and($this->chats->sent[0]->threadRef)->toBe('demo:C01:1001.000100');
});

test('a message to pass on goes to that person with the same tap, or nothing goes', function () {
    $this->alert->update(['proposal' => [
        'summary' => 'Decirle que vale y pasárselo a Lucía.',
        'reply' => ['to' => 'conversation', 'text' => 'Ok, se lo digo.'],
        'redirect' => ['to' => 'Lucía', 'text' => 'Lucía, ¿cerramos la migración?'],
    ]]);

    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert))->assertOk()->assertJsonCount(2, 'actions');

    expect($this->chats->sent)->toHaveCount(2)
        ->and($this->chats->sent[1]->text)->toBe('Lucía, ¿cerramos la migración?')
        ->and($this->chats->sent[1]->toLabel)->toBe('Lucía Romero');

    $this->chats->sent = [];
    $this->alert->update(['proposal' => [...$this->alert->proposal, 'redirect' => ['to' => 'Nadie Conocido', 'text' => 'Hola']]]);

    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert))->assertUnprocessable();

    expect($this->chats->sent)->toBe([]);
});

test('only a pending Slack alert of the person, with a reply proposed, can be sent', function () {
    $this->alert->update(['status' => AlertStatus::Resolved]);
    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert))->assertUnprocessable();

    $other = InboxItem::factory()->create(['source' => AlertSource::Slack, 'proposal' => ['reply' => ['to' => 'thread', 'text' => 'Hola']]]);
    $this->actingAs($this->user)->postJson(route('alerts.reply', $other))->assertNotFound();

    expect($this->chats->sent)->toBe([]);
});

test('one tap sends the proposed email reply, to everyone when that is the proposal', function () {
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(Outbox::class, $outbox = new FakeOutbox);
    $email = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Gmail,
        'external_id' => 'demo.2',
        'proposal' => ['summary' => 'Responder a todos.', 'reply' => ['to' => 'all', 'text' => 'Ok, lo firmo.']],
    ]);

    $this->actingAs($this->user)->postJson(route('alerts.reply', $email), ['timezone' => 'Europe/Madrid'])
        ->assertOk()
        ->assertJsonPath('actions.0.type', 'mail.send');

    expect(PendingAction::sole()->status)->toBe(ActionStatus::Executed)
        ->and($outbox->sent[0]->mode)->toBe('reply_all')
        ->and($outbox->sent[0]->body)->toStartWith('Ok, lo firmo.');
});
