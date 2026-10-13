<?php

namespace App\Http\Controllers;

use App\Alerts\AlertFeed;
use App\Jobs\WarmChats;
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
    public function __invoke(Request $request, AlertFeed $feed): Response
    {
        if ($request->user() === null) {
            return Inertia::render('landing', [
                'canRegister' => (bool) config('auth.registration'),
                'repositoryUrl' => config('app.repository_url'),
            ]);
        }

        WarmChats::dispatch($request->user());

        return Inertia::render('home', [
            // What came in lately, and every alert waiting for an answer, until answered.
            'alerts' => $feed->for($request->user()),
        ]);
    }
}
