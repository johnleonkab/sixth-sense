<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Settings\DisplaySize;
use App\Voice\VoiceSamples;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
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
