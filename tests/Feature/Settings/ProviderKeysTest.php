<?php

use App\Ai\ProviderKeys;
use App\Models\User;
use App\Scenes\DeepSeekSceneText;
use App\Voice\AssistantProfile;
use App\Voice\Providers\GeminiVoiceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const USER_GEMINI = 'AIzaSyUserGeminiKey0000000001';
const USER_DEEPSEEK = 'sk-user-deepseek-key-00000001';
const SYSTEM_GEMINI = 'system-gemini-key-0001';
const SYSTEM_DEEPSEEK = 'system-deepseek-key-01';

beforeEach(function () {
    config([
        'voice.providers.gemini.api_key' => SYSTEM_GEMINI,
        'ai.providers.deepseek.api_key' => SYSTEM_DEEPSEEK,
    ]);
});

test('a person can store their own keys, encrypted, and the page never receives them', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('settings.system.keys'), [
        'gemini' => 'own',
        'deepseek' => 'own',
        'gemini_api_key' => USER_GEMINI,
        'deepseek_api_key' => USER_DEEPSEEK,
    ])->assertRedirect();

    $row = DB::table('users')->where('id', $user->id)->first();
    $user->refresh();

    expect($row->gemini_api_key)->not->toContain(USER_GEMINI)
        ->and($row->deepseek_api_key)->not->toContain(USER_DEEPSEEK)
        ->and($user->gemini_api_key)->toBe(USER_GEMINI)
        ->and($user->deepseek_api_key)->toBe(USER_DEEPSEEK)
        ->and($user->toArray())->not->toHaveKeys(['gemini_api_key', 'deepseek_api_key']);

    $this->actingAs($user)->get(route('settings.system.index'))
        ->assertOk()
        ->assertDontSee(USER_GEMINI)
        ->assertDontSee(USER_DEEPSEEK)
        ->assertInertia(fn ($page) => $page
            ->where('keys.gemini.using', 'own')
            ->where('keys.deepseek.using', 'own')
            ->where('keys.gemini.system_ready', true));
});

test('a blank field keeps the stored key, and choosing the system key deletes it', function () {
    $user = User::factory()->create();
    $user->gemini_api_key = USER_GEMINI;
    $user->deepseek_api_key = USER_DEEPSEEK;
    $user->save();

    $this->actingAs($user)->put(route('settings.system.keys'), [
        'gemini' => 'own',
        'deepseek' => 'own',
        'gemini_api_key' => '',
        'deepseek_api_key' => '',
    ])->assertRedirect();

    expect($user->fresh()->gemini_api_key)->toBe(USER_GEMINI);

    $this->actingAs($user)->put(route('settings.system.keys'), [
        'gemini' => 'system',
        'deepseek' => 'system',
        'gemini_api_key' => USER_GEMINI,
        'deepseek_api_key' => USER_DEEPSEEK,
    ])->assertRedirect();

    $fresh = $user->fresh();

    expect($fresh->gemini_api_key)->toBeNull()->and($fresh->deepseek_api_key)->toBeNull();
    expect(DB::table('users')->where('id', $user->id)->value('gemini_api_key'))->toBeNull();
});

test('a key with a line break is rejected and is not remembered or flashed', function () {
    $user = User::factory()->create();
    $injected = "AIzaSyInjectedKey0000000001\r\nX-Evil: 1";

    $this->actingAs($user)
        ->from(route('settings.system.index'))
        ->put(route('settings.system.keys'), [
            'gemini' => 'own',
            'deepseek' => 'system',
            'gemini_api_key' => $injected,
            'deepseek_api_key' => '',
        ])
        ->assertRedirect(route('settings.system.index'))
        ->assertSessionHasErrors('gemini_api_key');

    expect($user->fresh()->gemini_api_key)->toBeNull()
        ->and(json_encode(session()->getOldInput()))->not->toContain('X-Evil')
        ->and(json_encode(session()->getOldInput()))->not->toContain('AIzaSyInjected');

    $this->actingAs($user)->get(route('settings.system.index'))->assertDontSee('X-Evil');
});

test('another person does not receive someone elses key, and mass assignment cannot set one', function () {
    $owner = User::factory()->create();
    $owner->gemini_api_key = USER_GEMINI;
    $owner->save();
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('settings.system.index'))
        ->assertDontSee(USER_GEMINI)
        ->assertInertia(fn ($page) => $page->where('keys.gemini.using', 'system'));

    $other->update(['gemini_api_key' => USER_GEMINI, 'deepseek_api_key' => USER_DEEPSEEK]);

    expect($other->fresh()->gemini_api_key)->toBeNull();
});

test('guests cannot change keys', function () {
    $this->put(route('settings.system.keys'), [
        'gemini' => 'own',
        'deepseek' => 'own',
        'gemini_api_key' => USER_GEMINI,
        'deepseek_api_key' => USER_DEEPSEEK,
    ])->assertRedirect(route('login'));
});

test('voice uses the persons gemini key and does not keep it for the next person', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['name' => 'auth_tokens/abc'])]);
    $owner = User::factory()->create();
    $owner->gemini_api_key = USER_GEMINI;
    $owner->save();
    $other = User::factory()->create();
    $provider = new GeminiVoiceProvider(config('voice.providers.gemini'), config('voice.session'));
    $assistant = new AssistantProfile('Habla en español.');

    $session = $provider->startSession($owner, $assistant);
    $provider->startSession($other, $assistant);

    expect(json_encode($session->toArray()))->not->toContain(USER_GEMINI)->not->toContain(SYSTEM_GEMINI);
    Http::assertSent(fn (Request $request) => ($request->header('x-goog-api-key')[0] ?? null) === USER_GEMINI);
    Http::assertSent(fn (Request $request) => ($request->header('x-goog-api-key')[0] ?? null) === SYSTEM_GEMINI);
});

test('deepseek calls use the signed in persons key, then the system key again', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => ['message' => 'no']], 401)]);
    $user = User::factory()->create();
    $user->deepseek_api_key = USER_DEEPSEEK;
    $user->save();

    $this->actingAs($user);
    expect(fn () => app(DeepSeekSceneText::class)->capable('Responde json.', 'Hola.', ['type' => 'OBJECT']))
        ->toThrow(RuntimeException::class);

    auth()->logout();
    expect(fn () => app(DeepSeekSceneText::class)->capable('Responde json.', 'Hola.', ['type' => 'OBJECT']))
        ->toThrow(RuntimeException::class);

    $tokens = Http::recorded(fn (Request $request) => true)->map(fn (array $pair) => $pair[0]->header('Authorization')[0] ?? null)->all();

    expect($tokens)->toBe(['Bearer '.USER_DEEPSEEK, 'Bearer '.SYSTEM_DEEPSEEK]);
});

test('a queued job uses its owners key and then forgets it', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owner->deepseek_api_key = 'sk-owner-deepseek-key-00001';
    $other->deepseek_api_key = 'sk-other-deepseek-key-00001';
    $owner->save();
    $other->save();

    $this->actingAs($owner);
    ReadsProviderKeyJob::dispatch($other);

    expect(test()->seenKey)->toBe('sk-other-deepseek-key-00001')
        ->and(app(ProviderKeys::class)->own('deepseek'))->toBe('sk-owner-deepseek-key-00001');

    expect(fn () => ThrowsProviderKeyJob::dispatch($other))->toThrow(RuntimeException::class);
    expect(app(ProviderKeys::class)->own('deepseek'))->toBe('sk-owner-deepseek-key-00001');
});

class ReadsProviderKeyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        test()->seenKey = app(ProviderKeys::class)->own('deepseek');
    }
}

class ThrowsProviderKeyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        throw new RuntimeException('stop');
    }
}
