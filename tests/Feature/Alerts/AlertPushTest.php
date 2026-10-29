<?php

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Push\PushSender;
use App\Alerts\Triage;
use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Models\InboxItem;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RecordingPushSender;

function pushSender(): RecordingPushSender
{
    $sender = new RecordingPushSender;
    app()->instance(PushSender::class, $sender);

    return $sender;
}

test('an important alert notifies the device once and remembers that message', function () {
    $sender = pushSender();
    $user = User::factory()->create();
    $subscription = PushSubscription::factory()->for($user)->create();
    $item = InboxItem::factory()->for($user)->create([
        'triage' => Triage::Judging,
        'actor_name' => 'Marta',
        'why' => 'Te pide revisar el flujo antes de las 12.',
    ]);

    event(new InboxItemCreated($item));

    expect($sender->sent)->toBeEmpty()
        ->and($item->refresh()->last_push_key)->toBeNull();

    $item->update(['triage' => Triage::Important, 'planning' => true]);
    event(new InboxItemUpdated($item->refresh()));

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]['endpoint'])->toBe($subscription->endpoint);

    $message = json_decode($sender->sent[0]['payload'], true);

    expect($message['web_push'])->toBe(8030)
        ->and($message['mutable'])->toBeFalse()
        ->and($message['notification']['title'])->toBe('Marta · Gmail')
        ->and($message['notification']['body'])->toBe('Te pide revisar el flujo antes de las 12.')
        ->and($message['notification']['navigate'])->toBe(url('/?alert='.$item->id))
        ->and($item->refresh()->last_push_key)->toBe($item->pushKey());

    $item->update(['planning' => false, 'proposal' => ['summary' => 'Puedo contestarle que lo miro.']]);
    event(new InboxItemUpdated($item->refresh()));

    expect($sender->sent)->toHaveCount(1);

    $item->update(['external_id' => '1.newer-message']);
    event(new InboxItemUpdated($item->refresh()));

    expect($sender->sent)->toHaveCount(2)
        ->and($item->refresh()->last_push_key)->toBe($item->pushKey());
});

test('a routine alert and a meeting reminder stay inside the app', function () {
    $sender = pushSender();
    $user = User::factory()->create();
    PushSubscription::factory()->for($user)->create();

    $routine = InboxItem::factory()->for($user)->create(['triage' => Triage::Routine]);
    $meeting = InboxItem::factory()->for($user)->create(['source' => AlertSource::Calendar]);

    event(new InboxItemCreated($routine));
    event(new InboxItemCreated($meeting));

    expect($sender->sent)->toBeEmpty()
        ->and($routine->refresh()->last_push_key)->toBeNull()
        ->and($meeting->refresh()->last_push_key)->toBeNull();
});

test('an important alert is kept until a device exists to receive it', function () {
    $sender = pushSender();
    $item = InboxItem::factory()->create(['triage' => Triage::Important]);

    event(new InboxItemCreated($item));

    expect($sender->sent)->toBeEmpty()
        ->and($item->refresh()->last_push_key)->toBeNull();
});

test('a push service that has forgotten the browser is dropped and the alert still counts as sent', function () {
    $sender = pushSender();
    $user = User::factory()->create();
    $subscription = PushSubscription::factory()->for($user)->create();
    $sender->gone[] = $subscription->endpoint;
    $item = InboxItem::factory()->for($user)->create(['triage' => Triage::Important]);

    event(new InboxItemCreated($item));

    expect(PushSubscription::query()->count())->toBe(0)
        ->and($item->refresh()->last_push_key)->toBe($item->pushKey());
});

test('a failed delivery can be tried again', function () {
    $sender = pushSender();
    $sender->fail = true;
    $user = User::factory()->create();
    PushSubscription::factory()->for($user)->create();
    $item = InboxItem::factory()->for($user)->create(['triage' => Triage::Important]);

    expect(fn () => event(new InboxItemCreated($item)))->toThrow(RuntimeException::class);
    expect($item->refresh()->last_push_key)->toBeNull();
});

test('a browser is registered only when its address is a known push service', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('push-subscriptions.store'), [
        'endpoint' => 'https://evil.example/push',
        'keys' => ['p256dh' => 'p256dh-secret-value', 'auth' => 'auth-secret-value'],
    ])->assertUnprocessable()
        ->assertJsonPath('errors.endpoint.0', 'Este navegador no usa un servicio de avisos conocido.');

    expect(PushSubscription::query()->count())->toBe(0);

    $this->actingAs($user)->postJson(route('push-subscriptions.store'), [
        'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/abc',
        'keys' => ['p256dh' => 'p256dh-secret-value', 'auth' => 'auth-secret-value'],
    ])->assertOk();

    $row = DB::table('push_subscriptions')->first();

    expect($row->endpoint)->toBe('https://updates.push.services.mozilla.com/wpush/v2/abc')
        ->and($row->p256dh)->not->toContain('p256dh-secret-value')
        ->and($row->auth)->not->toContain('auth-secret-value')
        ->and(PushSubscription::query()->first()->p256dh)->toBe('p256dh-secret-value')
        ->and(PushSubscription::query()->first()->content_encoding)->toBe('aes128gcm');
});

test('registering the same browser moves it to the person using it now', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $endpoint = 'https://web.push.apple.com/Qabc';
    $body = [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'p256dh-secret-value', 'auth' => 'auth-secret-value'],
    ];

    $this->actingAs($first)->postJson(route('push-subscriptions.store'), $body)->assertOk();
    $this->actingAs($second)->postJson(route('push-subscriptions.store'), $body)->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->first()->user_id)->toBe($second->id);

    $this->actingAs($first)->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])->assertOk();

    expect(PushSubscription::query()->count())->toBe(1);

    $this->actingAs($second)->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])->assertOk();

    expect(PushSubscription::query()->count())->toBe(0);
});

test('a guest is sent to sign in before registering a browser', function () {
    $this->post(route('push-subscriptions.store'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
        'keys' => ['p256dh' => 'p256dh-secret-value', 'auth' => 'auth-secret-value'],
    ])->assertRedirect(route('login'));
});

test('opening a notification shows that alert only to its owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $item = InboxItem::factory()->for($owner)->create([
        'source' => AlertSource::Calendar,
        'payload' => ['starts_at' => '2026-10-26T09:00:00+02:00'],
    ]);
    $mail = InboxItem::factory()->for($owner)->create(['status' => AlertStatus::Pending]);

    $this->get('/?alert='.$mail->id)
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toContain('/?alert='.$mail->id);

    $this->actingAs($other)->get('/?alert='.$mail->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('openAlert', null));

    $this->actingAs($owner)->get('/?alert='.$mail->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('openAlert.id', $mail->id)
            ->where('openAlert.source', 'gmail')
            ->where('openAlert.starts_at', null));

    $this->actingAs($owner)->get('/?alert='.$item->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('openAlert.id', $item->id)
            ->where('openAlert.source', 'calendar')
            ->where('openAlert.starts_at', '2026-10-26T09:00:00+02:00'));
});

test('alert settings share the public push key and keep the private one', function () {
    config([
        'services.vapid.public_key' => 'test-public-key',
        'services.vapid.private_key' => 'test-private-key-should-stay',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('settings.alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('vapidPublicKey', 'test-public-key'))
        ->assertDontSee('test-private-key-should-stay', false);
});
