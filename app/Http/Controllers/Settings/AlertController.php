<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Actions;
use App\Alerts\AlertSource;
use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Avisos\AlertSound;
use App\Avisos\ForMe;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\ImportantSender;
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

        $settings = $user->alertSettings()->get()->keyBy(fn ($setting): string => $setting->source->value);
        $senders = $user->importantSenders()->orderBy('email')->get(['id', 'email', 'name']);

        return Inertia::render('settings/alerts', [
            'sounds' => array_map(fn (AlertSound $sound): array => [
                'key' => $sound->value,
                'label' => $sound->label(),
            ], AlertSound::cases()),
            'sources' => array_map(fn (AlertSource $source): array => [
                'key' => $source->value,
                'name' => $source->label(),
                'description' => $source->description(),
                'enabled' => $user->wantsAlertsFrom($source),
                'capability' => $source->capability()->label(),
                'sound' => in_array($source, ForMe::SOURCES, true)
                    ? ($settings->get($source->value)?->sound ?: $source->defaultSound())
                    : null,
                'read_aloud' => (bool) ($settings->get($source->value)?->read_aloud ?? true),
                'senders' => $source === AlertSource::Gmail
                    ? $senders->map(fn (ImportantSender $sender): array => [
                        'id' => $sender->id,
                        'email' => $sender->email,
                        'name' => $sender->name,
                    ])->all()
                    : [],
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

    /**
     * The sound of one source, and whether active listening reads it aloud.
     */
    public function updateSound(Request $request, AlertSource $source): RedirectResponse
    {
        abort_unless(in_array($source, ForMe::SOURCES, true), 404);

        $data = $request->validate([
            'sound' => ['required', 'in:'.implode(',', AlertSound::values())],
            'read_aloud' => ['required', 'boolean'],
        ]);

        $request->user()->alertSettings()->updateOrCreate(
            ['source' => $source],
            ['sound' => $data['sound'], 'read_aloud' => $data['read_aloud']],
        );

        return back();
    }

    /**
     * Mark a Gmail sender as important. Their mail becomes an aviso.
     */
    public function storeSender(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $request->user()->importantSenders()->updateOrCreate(
            ['email' => strtolower($data['email'])],
            ['name' => $data['name'] ?? null],
        );

        return back();
    }

    /**
     * Stop treating a sender as important.
     */
    public function destroySender(Request $request, ImportantSender $sender): RedirectResponse
    {
        abort_unless($sender->user_id === $request->user()->id, 404);

        $sender->delete();

        return back();
    }
}
