<?php

namespace App\Http\Controllers\Wingman;

use App\Http\Controllers\Controller;
use App\Wingman\LiveCall;
use App\Wingman\MeetingStatus;
use App\Wingman\WingmanCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wingman's bubble: the call the person is in, and pausing it.
 */
class LiveController extends Controller
{
    public function show(Request $request, LiveCall $live): JsonResponse
    {
        return response()->json(['meeting' => $live->for($request->user())]);
    }

    /**
     * Pause listening to the call, or listen again.
     */
    public function pause(Request $request, int $meeting, WingmanCalls $calls, LiveCall $live): JsonResponse
    {
        $meeting = $request->user()->wingmanMeetings()->where('status', MeetingStatus::Live)->findOrFail($meeting);
        $paused = $request->validate(['paused' => ['required', 'boolean']])['paused'];

        return response()->json(['meeting' => $live->describe($calls->pause($meeting, $paused), LiveCall::LINES)]);
    }
}
