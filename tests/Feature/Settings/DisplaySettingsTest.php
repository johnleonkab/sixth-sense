<?php

use App\Models\User;
use App\Settings\DisplaySize;

test('the display page offers each size with the current one chosen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.display.index'))
        ->assertInertia(fn ($page) => $page->component('settings/display')
            ->where('current', 'normal')
            ->has('sizes', 3)
            ->where('sizes.2', ['key' => 'large', 'name' => 'Grande', 'description' => 'Todo más grande y fácil de leer de lejos.', 'scale' => 1.2]));
});

test('choosing a size scales the canvases everywhere', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('settings.display.update'), ['display_size' => 'large'])->assertRedirect();

    expect($user->refresh()->display_size)->toBe(DisplaySize::Large);

    $this->actingAs($user)->get(route('home'))->assertInertia(fn ($page) => $page->where('auth.user.display_scale', 1.2));
});

test('only known sizes are accepted', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('settings.display.update'), ['display_size' => 'huge'])
        ->assertSessionHasErrors('display_size');
});
