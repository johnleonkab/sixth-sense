<?php

use App\Jobs\WarmChats;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('visitors see the landing, with the code and a way in', function () {
    Queue::fake();
    config(['auth.registration' => false, 'app.repository_url' => 'https://github.com/acme/sixth-sense']);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('landing')
            ->where('canRegister', false)
            ->where('repositoryUrl', 'https://github.com/acme/sixth-sense'));

    Queue::assertNothingPushed();
});

test('the landing offers to create an account when registration is on', function () {
    config(['auth.registration' => true]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
});

test('signed-in users get the assistant, not the landing', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertInertia(fn (Assert $page) => $page->component('home'));

    Queue::assertPushed(WarmChats::class);
});
