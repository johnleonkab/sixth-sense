<?php

namespace App\Http\Controllers;

use App\Jobs\WarmChats;
use App\Models\InboxItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The landing for visitors; the assistant once signed in. Opening the
     * assistant gets the chat workspaces ready, so the first names said in
     * the conversation are already known.
     */
    public function __invoke(Request $request): Response
    {
        if ($request->user() === null) {
            return Inertia::render('landing', [
                'canRegister' => (bool) config('auth.registration'),
                'repositoryUrl' => config('app.repository_url'),
            ]);
        }

        WarmChats::dispatch($request->user());

        return Inertia::render('home', [
            // Alerts waiting for an answer stay on screen until answered.
            'alerts' => $request->user()->inboxItems()->pending()->limit(30)->get()
                ->map(fn (InboxItem $item): array => $item->toAlert())
                ->all(),
        ]);
    }
}
