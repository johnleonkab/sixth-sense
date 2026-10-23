<?php

namespace App\Http\Controllers;

use App\Actions\Actions;
use App\Models\PendingAction;
use App\Sense\OverrideFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function confirm(Request $request, Actions $actions, int $action): JsonResponse
    {
        return response()->json($actions->confirm($this->find($request, $action))->toState());
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

    public function cancel(Request $request, Actions $actions, int $action): JsonResponse
    {
        return response()->json($actions->cancel($this->find($request, $action))->toState());
    }

    public function undo(Request $request, Actions $actions, int $action): JsonResponse
    {
        return response()->json($actions->undo($this->find($request, $action))->toState());
    }

    private function find(Request $request, int $action): PendingAction
    {
        return $request->user()->pendingActions()->findOrFail($action);
    }
}
