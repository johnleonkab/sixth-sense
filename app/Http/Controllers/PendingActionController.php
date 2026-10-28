<?php

namespace App\Http\Controllers;

use App\Actions\Actions;
use App\Models\PendingAction;
use App\Sense\OverrideFile;
use App\Services\PostHogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The person's answers to a proposed change: by voice (the app recognises
 * "sí", "cancela", "deshaz" in what they say) or with the buttons. The
 * assistant itself can never confirm.
 */
class PendingActionController extends Controller
{
    public function show(Request $request, int $action): JsonResponse
    {
        return response()->json($this->find($request, $action)->toState());
    }

    public function confirm(Request $request, Actions $actions, PostHogService $posthog, int $action): JsonResponse
    {
        $pendingAction = $actions->confirm($this->find($request, $action));

        $posthog->capture((string) $request->user()->getAuthIdentifier(), 'pending_action_confirmed', [
            'action_type' => $pendingAction->type,
        ]);
        Log::channel('posthog')->info('pending_action_confirmed', [
            'action_type' => $pendingAction->type,
        ]);

        return response()->json($pendingAction->toState());
    }

    /**
     * The person rewrote a markdown proposal on screen and wants that text saved.
     */
    public function override(Request $request, OverrideFile $override, int $action): JsonResponse
    {
        $contents = $request->validate([
            'contents' => ['required', 'string', 'max:100000'],
        ])['contents'];

        return response()->json($override->apply($this->find($request, $action), $contents)->toState());
    }

    public function cancel(Request $request, Actions $actions, PostHogService $posthog, int $action): JsonResponse
    {
        $pendingAction = $actions->cancel($this->find($request, $action));

        $posthog->capture((string) $request->user()->getAuthIdentifier(), 'pending_action_cancelled', [
            'action_type' => $pendingAction->type,
        ]);
        Log::channel('posthog')->info('pending_action_cancelled', [
            'action_type' => $pendingAction->type,
        ]);

        return response()->json($pendingAction->toState());
    }

    public function undo(Request $request, Actions $actions, PostHogService $posthog, int $action): JsonResponse
    {
        $pendingAction = $actions->undo($this->find($request, $action));

        $posthog->capture((string) $request->user()->getAuthIdentifier(), 'pending_action_undone', [
            'action_type' => $pendingAction->type,
        ]);
        Log::channel('posthog')->info('pending_action_undone', [
            'action_type' => $pendingAction->type,
        ]);

        return response()->json($pendingAction->toState());
    }

    private function find(Request $request, int $action): PendingAction
    {
        return $request->user()->pendingActions()->findOrFail($action);
    }
}
