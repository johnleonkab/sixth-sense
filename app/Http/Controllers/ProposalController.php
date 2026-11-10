<?php

namespace App\Http\Controllers;

use App\Comms\Gate\Gate;
use App\Models\Comms\CommsProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The person's decision on a proposal. The assistant cannot approve one.
 */
class ProposalController extends Controller
{
    public function approve(Request $request, Gate $gate, CommsProposal $proposal): RedirectResponse|JsonResponse
    {
        $this->owned($request, $proposal);
        abort_if($gate->approve($proposal) === null, 422);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aprobado. Tienes unos segundos para deshacer.']);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function reject(Request $request, Gate $gate, CommsProposal $proposal): RedirectResponse|JsonResponse
    {
        $this->owned($request, $proposal);
        $gate->reject($proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rechazado.']);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function edit(Request $request, Gate $gate, CommsProposal $proposal): RedirectResponse|JsonResponse
    {
        $this->owned($request, $proposal);

        $text = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
        ])['text'];

        $gate->edit($proposal, $text);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Texto cambiado.']);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function undo(Request $request, Gate $gate, CommsProposal $proposal): RedirectResponse|JsonResponse
    {
        $this->owned($request, $proposal);
        $gate->undo($proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Deshecho.']);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    private function owned(Request $request, CommsProposal $proposal): void
    {
        abort_unless($proposal->user_id === $request->user()?->id, 404);
    }
}
