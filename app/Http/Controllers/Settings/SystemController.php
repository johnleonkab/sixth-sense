<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Settings\DisplaySize;
use App\Voice\VoiceSamples;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class SystemController extends Controller
{
    /**
     * How the assistant looks and sounds.
     */
    public function index(Request $request): Response
    {
        $voices = config('voice.providers.gemini.voices', []);

        return Inertia::render('settings/system', [
            'sizes' => array_map(fn (DisplaySize $size): array => [
                'key' => $size->value,
                'name' => $size->label(),
                'description' => $size->description(),
                'scale' => $size->scale(),
            ], DisplaySize::cases()),
            'current' => $request->user()->display_size->value,
            'voices' => array_map(fn (string $name, string $tone): array => [
                'name' => $name,
                'tone' => $tone,
                'sample_url' => route('settings.system.voice.sample', $name, absolute: false),
            ], array_keys($voices), $voices),
            'voice' => $request->user()->voice ?? config('voice.providers.gemini.voice'),
            'default_voice' => config('voice.providers.gemini.voice'),
            'keys' => $this->keys($request->user()),
        ]);
    }

    /**
     * Choose how large canvases are shown.
     */
    public function updateDisplay(Request $request): RedirectResponse
    {
        $size = $request->validate(['display_size' => ['required', Rule::enum(DisplaySize::class)]])['display_size'];

        $request->user()->update(['display_size' => $size]);

        return back();
    }

    /**
     * Choose the assistant's voice, from the next conversation on.
     */
    public function updateVoice(Request $request): RedirectResponse
    {
        $voice = $request->validate(['voice' => ['required', 'string', Rule::in(array_keys(config('voice.providers.gemini.voices', [])))]])['voice'];

        $request->user()->update(['voice' => $voice]);

        return back();
    }

    /**
     * Use the person's own Gemini and DeepSeek keys, or go back to the system ones.
     * A blank field keeps the key already stored. Going back to the system deletes it.
     */
    public function updateKeys(Request $request): RedirectResponse
    {
        $request->merge([
            'gemini_api_key' => $this->trimmed($request->input('gemini_api_key')),
            'deepseek_api_key' => $this->trimmed($request->input('deepseek_api_key')),
        ]);

        $data = $request->validate([
            'gemini' => ['required', Rule::in(['system', 'own'])],
            'deepseek' => ['required', Rule::in(['system', 'own'])],
            'gemini_api_key' => ['nullable', 'string', 'min:20', 'max:500', 'regex:/^[A-Za-z0-9._~+\/=-]+$/'],
            'deepseek_api_key' => ['nullable', 'string', 'min:20', 'max:500', 'regex:/^[A-Za-z0-9._~+\/=-]+$/'],
        ], [
            'gemini_api_key.regex' => 'La clave de Gemini no es válida.',
            'deepseek_api_key.regex' => 'La clave de DeepSeek no es válida.',
            'gemini_api_key.min' => 'La clave de Gemini no es válida.',
            'deepseek_api_key.min' => 'La clave de DeepSeek no es válida.',
        ]);

        $user = $request->user();
        $this->applyKey($user, 'gemini_api_key', $data['gemini'], $data['gemini_api_key'] ?? null);
        $this->applyKey($user, 'deepseek_api_key', $data['deepseek'], $data['deepseek_api_key'] ?? null);
        $user->save();

        return back();
    }

    /**
     * What the page may know: which key is in use, never the key itself.
     *
     * @return array{gemini: array{using: string, system_ready: bool}, deepseek: array{using: string, system_ready: bool}}
     */
    private function keys(User $user): array
    {
        return [
            'gemini' => [
                'using' => filled($user->gemini_api_key) ? 'own' : 'system',
                'system_ready' => filled(config('voice.providers.gemini.api_key')),
            ],
            'deepseek' => [
                'using' => filled($user->deepseek_api_key) ? 'own' : 'system',
                'system_ready' => filled(config('ai.providers.deepseek.api_key')),
            ],
        ];
    }

    private function applyKey(User $user, string $column, string $mode, ?string $key): void
    {
        if ($mode === 'system') {
            $user->{$column} = null;

            return;
        }

        if (filled($key)) {
            $user->{$column} = $key;

            return;
        }

        if (blank($user->{$column})) {
            throw ValidationException::withMessages([
                $column => 'Escribe la clave, o usa la del sistema.',
            ]);
        }
    }

    private function trimmed(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A short sample of a voice, to hear it before choosing it.
     */
    public function sample(string $voice, VoiceSamples $samples): HttpResponse
    {
        abort_unless(array_key_exists($voice, config('voice.providers.gemini.voices', [])), 404);

        try {
            $path = $samples->pathFor($voice);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Ahora no se puede escuchar esta voz.'], 503);
        }

        return Storage::disk('local')->response($path, "{$voice}.wav", [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
