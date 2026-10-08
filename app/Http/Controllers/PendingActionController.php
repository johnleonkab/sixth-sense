<?php

namespace App\Http\Controllers;

use App\Actions\Actions;
use App\Models\PendingAction;
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
