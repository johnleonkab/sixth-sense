<?php

namespace App\Http\Controllers;

use App\Alerts\AlertFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertFeedController extends Controller
{
    /**
     * Everything that came in lately and where each stands.
     */
    public function __invoke(Request $request, AlertFeed $feed): JsonResponse
    {
        return response()->json(['items' => $feed->for($request->user())]);
    }

    /**
     * Older alerts, a page at a time.
     */
    public function history(Request $request, AlertFeed $feed): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['nullable', 'in:'.implode(',', AlertFeed::HISTORY_FILTERS)],
            'before' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($feed->history($request->user(), $validated['filter'] ?? 'all', $validated['before'] ?? null));
    }
}
