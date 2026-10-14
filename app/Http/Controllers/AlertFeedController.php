<?php

namespace App\Http\Controllers;

use App\Alerts\AlertFeed;
use App\Alerts\AlertStatus;
use App\Events\InboxItemUpdated;
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

    /**
     * The person dismissed an alert from the screen (its button, or swiping it away).
     */
    public function dismiss(Request $request, int $item): JsonResponse
    {
        $item = $request->user()->inboxItems()->findOrFail($item);

        if (! in_array($item->status, [AlertStatus::Resolved, AlertStatus::Dismissed], true)) {
            $item->update(['status' => AlertStatus::Dismissed, 'resolution' => 'dismissed', 'resolved_at' => now(), 'planning' => false]);
            InboxItemUpdated::dispatch($item);
        }

        return response()->json(['item' => $item->toAlert()]);
    }
}
