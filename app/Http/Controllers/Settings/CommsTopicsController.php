<?php

namespace App\Http\Controllers\Settings;

use App\Comms\Topics\TopicReview;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Confirm, correct, merge or drop a topic. Each save is a user.configured event.
 */
class CommsTopicsController extends Controller
{
    public function confirm(Request $request, TopicReview $topics): RedirectResponse
    {
        $subject = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
        ])['subject'];

        abort_unless($topics->confirm($request->user(), $subject), 404);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tema confirmado.']);

        return back();
    }

    public function update(Request $request, TopicReview $topics): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        abort_unless($topics->rename($request->user(), $data['subject'], $data['name']), 404);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nombre actualizado.']);

        return back();
    }

    public function storeNickname(Request $request, TopicReview $topics): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
            'alias' => ['required', 'string', 'max:80'],
        ]);

        abort_unless($topics->addNickname($request->user(), $data['subject'], $data['alias']), 404);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Apodo añadido.']);

        return back();
    }

    public function destroyNickname(Request $request, TopicReview $topics): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
            'fact' => ['required', 'integer'],
        ]);

        abort_unless($topics->removeNickname($request->user(), $data['subject'], (int) $data['fact']), 404);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Apodo quitado.']);

        return back();
    }

    public function merge(Request $request, TopicReview $topics): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:191'],
            'into' => ['required', 'string', 'max:191'],
        ]);

        abort_unless($topics->merge($request->user(), $data['from'], $data['into']), 404);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Temas unidos.']);

        return back();
    }

    public function destroy(Request $request, TopicReview $topics): RedirectResponse
    {
        $subject = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
        ])['subject'];

        abort_unless($topics->reject($request->user(), $subject), 404);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tema descartado.']);

        return back();
    }
}
