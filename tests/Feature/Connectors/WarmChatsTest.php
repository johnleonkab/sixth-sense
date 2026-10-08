<?php

use App\Connections\Provider;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Jobs\WarmChats;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Voice\Providers\FakeVoiceProvider;
use App\Voice\VoiceProvider;
use Illuminate\Support\Facades\Queue;

test('opening the assistant gets the chat workspaces ready', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertOk();

    Queue::assertPushed(WarmChats::class, fn (WarmChats $job) => $job->user->is($user));
});

test('the hourly warm-up only queues users with slack connected', function () {
    Queue::fake();
    $withSlack = ConnectedAccount::factory()->create(['provider' => Provider::Slack])->user;
    ConnectedAccount::factory()->create(['provider' => Provider::Google]);

    $this->artisan('chats:warm')->assertSuccessful();

    Queue::assertPushed(WarmChats::class, 1);
    Queue::assertPushed(WarmChats::class, fn (WarmChats $job) => $job->user->is($withSlack));
});

test('the assistant starts knowing who and where the user talks most', function () {
    $this->app->instance(Chats::class, new FakeChats);
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);

    $this->actingAs(User::factory()->create())->postJson(route('voice.sessions.store'))->assertCreated();

    expect($voice->started[0]['assistant']->instructions)
        ->toContain('Marta Ruiz (Product Manager) [demo:U01]')
        ->toContain('#producto [demo:C01]')
        ->not->toContain('#Marta Ruiz');
});

test('without slack the assistant gets no chat context', function () {
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);

    $this->actingAs(User::factory()->create())->postJson(route('voice.sessions.store'))->assertCreated();

    expect($voice->started[0]['assistant']->instructions)->not->toContain('Slack últimamente');
});
