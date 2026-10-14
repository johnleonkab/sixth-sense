<?php

namespace App\Http\Controllers\Wingman;

use App\Http\Controllers\Controller;
use App\Models\WingmanInsight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What the person says of Wingman's cards: whether one helped, or to drop it.
 */
class InsightController extends Controller
{
    public function feedback(Request $request, int $insight): JsonResponse
    {
        $insight = $this->own($request, $insight);
        $insight->update(['feedback' => $request->validate(['value' => ['nullable', Rule::in(['up', 'down'])]])['value'] ?? null]);

        return response()->json(['insight' => $insight->toCard()]);
    }

    public function dismiss(Request $request, int $insight): JsonResponse
    {
        $insight = $this->own($request, $insight);
        $insight->update(['dismissed_at' => now()]);

        return response()->json(['dismissed' => true]);
    }

    private function own(Request $request, int $insight): WingmanInsight
    {
        return WingmanInsight::query()
            ->whereHas('meeting', fn ($meetings) => $meetings->where('user_id', $request->user()->id))
            ->findOrFail($insight);
    }
}
