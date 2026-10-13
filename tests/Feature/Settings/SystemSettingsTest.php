<?php

use App\Models\User;
use App\Settings\DisplaySize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('the display page offers each size with the current one chosen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.system.index'))
        ->assertInertia(fn ($page) => $page->component('settings/system')
            ->where('current', 'normal')
            ->has('sizes', 3)
            ->where('sizes.2', ['key' => 'large', 'name' => 'Grande', 'description' => 'Todo más grande y fácil de leer de lejos.', 'scale' => 1.2]));
});

test('choosing a size scales the canvases everywhere', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('settings.system.display'), ['display_size' => 'large'])->assertRedirect();

    expect($user->refresh()->display_size)->toBe(DisplaySize::Large);

    $this->actingAs($user)->get(route('home'))->assertInertia(fn ($page) => $page->where('auth.user.display_scale', 1.2));
});

test('only known sizes are accepted', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('settings.system.display'), ['display_size' => 'huge'])
        ->assertSessionHasErrors('display_size');
});

test('the system page offers the gemini voices, with the default one chosen until the person picks another', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.system.index'))
        ->assertInertia(fn ($page) => $page->component('settings/system')
            ->where('voice', 'Kore')
            ->where('default_voice', 'Kore')
            ->has('voices', 30)
            ->where('voices.0', ['name' => 'Zephyr', 'tone' => 'Brillante', 'sample_url' => '/settings/system/voices/Zephyr/sample']));

    $this->actingAs($user)->put(route('settings.system.voice'), ['voice' => 'Puck'])->assertRedirect();

    expect($user->refresh()->voice)->toBe('Puck');
});

test('only offered voices are accepted', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('settings.system.voice'), ['voice' => 'Darth'])
        ->assertSessionHasErrors('voice');
});

test('the old display address leads to the system page', function () {
    $this->actingAs(User::factory()->create())->get('/settings/display')->assertRedirect('/settings/system');
});

test('a voice can be heard before choosing it: made once with gemini, then kept', function () {
    Storage::fake('local');
    config(['voice.providers.gemini.api_key' => 'test-key', 'voice.providers.gemini.sample_model' => 'tts-model']);
    Http::fake(['generativelanguage.googleapis.com/v1beta/models/tts-model:generateContent' => Http::response([
        'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode('RIFF-puck')]]]]]],
    ])]);
    $user = User::factory()->create();

    $first = $this->actingAs($user)->get(route('settings.system.voice.sample', 'Puck'));
    $this->actingAs($user)->get(route('settings.system.voice.sample', 'Puck'))->assertOk();

    $first->assertOk()->assertHeader('Content-Type', 'audio/wav');
    expect($first->streamedContent())->toBe('RIFF-puck');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Puck'
        && str_contains($request['contents'][0]['parts'][0]['text'], 'Puck'));
});

test('raw pcm samples are wrapped as wav, so any browser plays them', function () {
    Storage::fake('local');
    config(['voice.providers.gemini.api_key' => 'test-key']);
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode(str_repeat("\0", 480))]]]]]]])]);

    $sample = $this->actingAs(User::factory()->create())->get(route('settings.system.voice.sample', 'Kore'))->streamedContent();

    expect(substr($sample, 0, 4))->toBe('RIFF')
        ->and(substr($sample, 8, 4))->toBe('WAVE')
        ->and(unpack('V', substr($sample, 24, 4))[1])->toBe(24000)
        ->and(strlen($sample))->toBe(44 + 480);
});

test('only offered voices have samples, and a failing gemini says so without breaking', function () {
    Storage::fake('local');
    config(['voice.providers.gemini.api_key' => 'test-key']);
    Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.system.voice.sample', 'Darth'))->assertNotFound();
    $this->actingAs($user)->get(route('settings.system.voice.sample', 'Kore'))->assertStatus(503);
});
