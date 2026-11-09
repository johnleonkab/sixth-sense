<?php

namespace App\Http\Controllers;

use App\Comms\Cutover\HomeFeed;
use App\Comms\Cutover\OpenItem;
use App\Comms\Intents\IntentBoard;
use App\Jobs\WarmChats;
use App\Models\InboxItem;
use Illuminate\Http\RedirectResponse;
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
    public function __invoke(Request $request, HomeFeed $home, OpenItem $open, IntentBoard $intents): Response|RedirectResponse
    {
        $user = $request->user();
        $alertId = $request->integer('alert');

        if ($user === null) {
            if ($alertId > 0) {
                return redirect()->guest(route('login'));
            }

            return Inertia::render('landing', [
                'canRegister' => (bool) config('auth.registration'),
                'repositoryUrl' => config('app.repository_url'),
            ]);
        }

        WarmChats::dispatch($user);

        $openAlert = null;

        if ($alertId > 0) {
            $item = InboxItem::query()->whereBelongsTo($user)->find($alertId);
            $startsAt = $item?->payload['starts_at'] ?? null;

            $openAlert = $item === null ? null : [
                'id' => $item->id,
                'source' => $item->source->value,
                'starts_at' => is_string($startsAt) ? $startsAt : null,
            ];
        }

        return Inertia::render('home', [
            // What came in lately, and every alert waiting for an answer, until answered.
            'alerts' => $home->items($user),
            'openAlert' => $openAlert,
            'openComms' => $open->for($user),
            'intents' => $intents->for($user),
        ]);
    }
}
