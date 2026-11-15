<?php

use App\Alerts\AlertSource;
use App\Connections\Provider;
use App\Events\InboxItemCreated;
use App\Models\AlertSetting;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('a webhook without a valid signature is rejected', function () {
    config(['avisos.webhooks.slack' => 'secret']);

    $this->postJson(route('avisos.webhook', 'slack'), ['account' => 'me@example.com', 'external_id' => '1'])
        ->assertUnauthorized();

    $this->postJson(route('avisos.webhook', 'slack'), ['account' => 'me@example.com', 'external_id' => '1'], [
        'X-Avisos-Signature' => 'not-the-signature',
    ])->assertUnauthorized();
});

test('an empty webhook secret rejects every delivery', function () {
    config(['avisos.webhooks.slack' => null]);
    $body = json_encode(['account' => 'me@example.com', 'external_id' => '1', 'direct_message' => true]);

    $this->call('POST', route('avisos.webhook', 'slack'), content: $body, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_AVISOS_SIGNATURE' => hash_hmac('sha256', $body, ''),
    ])->assertUnauthorized();

    expect(InboxItem::query()->count())->toBe(0);
});

test('a signed direct message becomes one aviso and a repeat does not', function () {
    Event::fake([InboxItemCreated::class]);
    config(['avisos.webhooks.slack' => 'secret']);
    $user = User::factory()->create();
    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
    ]);
    AlertSetting::factory()->for($user)->create(['source' => AlertSource::Slack, 'enabled' => true]);
    $payload = [
        'account' => 'me@example.com',
        'external_id' => 'D1:1.1',
        'actor_name' => 'Marta Ruiz',
        'title' => 'Mensaje directo',
        'body' => '¿Puedes revisar el prepago?',
        'direct_message' => true,
    ];
    $body = json_encode($payload);
    $headers = ['HTTP_X_AVISOS_SIGNATURE' => hash_hmac('sha256', $body, 'secret'), 'CONTENT_TYPE' => 'application/json'];

    $this->call('POST', route('avisos.webhook', 'slack'), content: $body, server: $headers)->assertNoContent();
    $this->call('POST', route('avisos.webhook', 'slack'), content: $body, server: $headers)->assertNoContent();

    $item = $user->inboxItems()->sole();

    expect($item->source)->toBe(AlertSource::Slack)
        ->and($item->external_id)->toBe('D1:1.1')
        ->and($item->why)->toBe('Mensaje directo de Marta Ruiz.')
        ->and($item->user_id)->toBe($user->id);

    Event::assertDispatchedTimes(InboxItemCreated::class, 1);
});

test('an unknown webhook source is not found', function () {
    config(['avisos.webhooks.nope' => 'secret']);

    $this->postJson(route('avisos.webhook', 'nope'), [])->assertNotFound();
});
