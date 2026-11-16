<?php

namespace App\Http\Controllers\Settings;

use App\Comms\Nightly\NightChanges;
use App\Http\Controllers\Controller;
use App\Models\Comms\CommsReconcileChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Cambios de la noche, under Contexto. Saving the switches is a user.configured event.
 */
class NightChangesController extends Controller
{
    public function update(Request $request, NightChanges $night): RedirectResponse
    {
        $classes = $request->validate([
            'classes' => ['required', 'array'],
            'classes.confirm_link' => ['required', 'boolean'],
            'classes.relink_uncertain' => ['required', 'boolean'],
            'classes.settle_quoted' => ['required', 'boolean'],
            'classes.merge' => ['required', 'boolean'],
        ])['classes'];

        $night->save($request->user(), $classes);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Guardado. La próxima noche aplica solo lo que dejaste encendido.']);

        return back();
    }

    public function answer(Request $request, CommsReconcileChange $change, NightChanges $night): RedirectResponse
    {
        abort_unless($change->user_id === $request->user()->id, 404);

        $answer = $request->validate([
            'answer' => ['required', 'string', Rule::in(['igual', 'no es igual', 'deshacer'])],
        ])['answer'];

        $night->answer($request->user(), $change, $answer);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anotado.']);

        return back();
    }
}
