<?php

namespace App\Http\Controllers\Wingman;

use App\Http\Controllers\Controller;
use App\Wingman\WingmanCalls;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the "Wingman para Meet" extension tells sixth-sense.
 */
class ExtensionController extends Controller
{
    /** Segments in one request, at most. */
    private const MAX_SEGMENTS = 200;

    /**
     * Whose extension this is: to check the connection from its window.
     */
    public function ping(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->name]);
    }

    /**
     * The person joined a call.
     */
    public function start(Request $request, WingmanCalls $calls): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/'],
            'joined_at' => ['required', 'date'],
        ]);

        $meeting = $calls->start($request->user(), $data['code'], CarbonImmutable::parse($data['joined_at']));

        return response()->json(['meeting_id' => $meeting->id, 'title' => $meeting->title]);
    }

    /**
     * What is being said in it.
     */
    public function segments(Request $request, int $meeting, WingmanCalls $calls): JsonResponse
    {
        $meeting = $request->user()->wingmanMeetings()->findOrFail($meeting);

        $data = $request->validate([
            'segments' => ['present', 'array', 'max:'.self::MAX_SEGMENTS],
            'segments.*.client_id' => ['required', 'string', 'max:100'],
            'segments.*.speaker' => ['required', 'string', 'max:200'],
            'segments.*.text' => ['required', 'string', 'max:10000'],
            'segments.*.final' => ['sometimes', 'boolean'],
            'segments.*.spoken_at' => ['required', 'date'],
        ]);

        return response()->json(['taken' => $calls->record($meeting, $data['segments'])]);
    }

    /**
     * The person left it.
     */
    public function end(Request $request, int $meeting, WingmanCalls $calls): JsonResponse
    {
        $meeting = $request->user()->wingmanMeetings()->findOrFail($meeting);
        $data = $request->validate(['left_at' => ['required', 'date']]);

        $calls->end($meeting, CarbonImmutable::parse($data['left_at']));

        return response()->json(['ended' => true]);
    }
}
