<?php

namespace App\Http\Controllers\Wingman;

use App\Http\Controllers\Controller;
use App\Models\WingmanAction;
use App\Wingman\Closing\MeetingActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What is left to do after a call: review one (it becomes a pending change
 * waiting for the "sí"), drop it, or bring it back.
 */
class ActionController extends Controller
{
    public function __construct(private MeetingActions $actions) {}

    public function review(Request $request, int $action): JsonResponse
    {
        return response()->json(['action' => $this->actions->review($this->own($request, $action))->toCard()]);
    }

    public function dismiss(Request $request, int $action): JsonResponse
    {
        return response()->json(['action' => $this->actions->dismiss($this->own($request, $action), true)->toCard()]);
    }

    public function restore(Request $request, int $action): JsonResponse
    {
        return response()->json(['action' => $this->actions->dismiss($this->own($request, $action), false)->toCard()]);
    }

    private function own(Request $request, int $action): WingmanAction
    {
        return WingmanAction::query()
            ->whereHas('meeting', fn ($meetings) => $meetings->where('user_id', $request->user()->id))
            ->findOrFail($action);
    }
}
