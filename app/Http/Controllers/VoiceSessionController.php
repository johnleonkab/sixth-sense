<?php

namespace App\Http\Controllers;

use App\Connectors\Chat\Chats;
use App\Connectors\Issues\IssueTracker;
use App\Http\Requests\StartVoiceSessionRequest;
use App\Services\PostHogService;
use App\Voice\AssistantProfile;
use App\Voice\Tools\Toolbox;
use App\Voice\VoiceProvider;
use App\Voice\VoiceProviderUnavailable;
use Illuminate\Http\JsonResponse;

class VoiceSessionController extends Controller
{
    /**
     * Issue a short-lived grant so the browser can talk to the voice provider.
     */
    public function __invoke(StartVoiceSessionRequest $request, VoiceProvider $voice, Toolbox $toolbox, Chats $chats, IssueTracker $issues, PostHogService $posthog): JsonResponse
    {
        try {
            $session = $voice->startSession(
                $request->user(),
                AssistantProfile::for($request->user(), $request->now(), $toolbox, $chats, $issues),
            );
        } catch (VoiceProviderUnavailable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No puedo conectar con el servicio de voz ahora mismo. Inténtalo de nuevo en un momento.',
            ], 503);
        }

        $posthog->capture((string) $request->user()->getAuthIdentifier(), 'voice_session_started');

        return response()->json($session->toArray(), 201);
    }
}
