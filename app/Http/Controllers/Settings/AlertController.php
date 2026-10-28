<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Actions;
use App\Alerts\AlertSource;
use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\PendingAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AlertController extends Controller
{
    /**
     * Every alert source, whether it is on and which accounts it watches.
     */
    public function index(Request $request, SlackAutonomy $autonomy): Response
    {
        $user = $request->user();

        return Inertia::render('settings/alerts', [
            'sources' => array_map(fn (AlertSource $source): array => [
                'key' => $source->value,
                'name' => $source->label(),
                'description' => $source->description(),
                'enabled' => $user->wantsAlertsFrom($source),
                'capability' => $source->capability()->label(),
                'accounts' => $user->connectedAccounts()->ofProvider($source->capability()->provider())->oldest()->orderBy('id')->get()
                    ->map(fn (ConnectedAccount $account): array => [
                        'id' => $account->id,
                        'email' => $account->email,
                        'watched' => $account->can($source->capability()),
                    ])->all(),
            ], AlertSource::cases()),
            'vapidPublicKey' => filled(config('services.vapid.public_key')) ? config('services.vapid.public_key') : null,
            'slackAutonomy' => $autonomy->present($user),
            'autonomyAction' => session('autonomy_action'),
        ]);
    }

    /**
     * Drop one Slack action a step. It applies at once.
     */
    public function lowerAutonomy(Request $request, SlackAutonomy $autonomy): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:reply,redirect,task'],
            'level' => ['required', 'in:watch,draft'],
        ]);

        try {
            $autonomy->lower($request->user(), SlackAction::from($data['action']), AutonomyLevel::from($data['level']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['slack_autonomy' => $exception->getMessage()]);
        }

        return back();
    }

    /**
     * Ask before raising a Slack action. The rise still waits for "sí".
     */
    public function promoteAutonomy(Request $request, SlackAutonomy $autonomy): RedirectResponse
    {
        $action = SlackAction::from($request->validate([
            'action' => ['required', 'in:reply,redirect,task'],
        ])['action']);
        $pending = $autonomy->offer($request->user(), $action);

        if ($pending === null) {
            return back()->withErrors(['slack_autonomy' => $autonomy->blocked($request->user(), $action)]);
        }

        return back()->with('autonomy_action', $pending->toState());
    }

    /**
     * The person said yes to a rise that was waiting on this page.
     */
    public function confirmAutonomy(Request $request, Actions $actions, int $pending): RedirectResponse
    {
        $actions->confirm($this->promotion($request, $pending));

        return back();
    }

    /**
     * The person declined the rise.
     */
    public function cancelAutonomy(Request $request, Actions $actions, int $pending): RedirectResponse
    {
        $actions->cancel($this->promotion($request, $pending));

        return back();
    }

    private function promotion(Request $request, int $pending): PendingAction
    {
        return $request->user()->pendingActions()->where('type', 'slack.autonomy')->findOrFail($pending);
    }

    /**
     * Switch alerts from a source on or off.
     */
    public function update(Request $request, AlertSource $source): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $request->user()->alertSettings()->updateOrCreate(['source' => $source], ['enabled' => $enabled]);

        return back();
    }
}
