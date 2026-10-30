<?php

namespace App\Http\Controllers;

use App\Actions\Actions;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Models\PendingAction;
use App\Voice\Tools\Slack\ProposeSlackMessage;
use App\Voice\Tools\ToolContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Send what an alert proposes, with one tap: the tap is the "sí". The reply,
 * and the message to pass on to someone else when there is one, go out once
 * the undo window closes, as every confirmed action does.
 */
class AlertReplyController extends Controller
{
    public function __invoke(Request $request, int $item, ProposeSlackMessage $propose, Actions $actions): JsonResponse
    {
        $alert = $request->user()->inboxItems()->findOrFail($item);
        $reply = $alert->proposal['reply'] ?? null;
        $redirect = $alert->proposal['redirect'] ?? null;
        $messages = [];

        if (filled($reply['text'] ?? null)) {
            // In its thread, or in the conversation it came from.
            $messages[] = ['text' => $reply['text'], ...in_array($reply['to'] ?? 'thread', ['conversation', 'all'], true)
                ? ['to' => Str::beforeLast($alert->external_id, ':')]
                : ['reply_to' => $alert->external_id]];
        }

        if (filled($redirect['text'] ?? null) && filled($redirect['to'] ?? null)) {
            $messages[] = ['to' => $redirect['to'], 'text' => $redirect['text']];
        }

        abort_unless($alert->source === AlertSource::Slack && $alert->status === AlertStatus::Pending && $messages !== [], 422, 'Este aviso no tiene nada de Slack que enviar.');

        $timezone = $request->string('timezone', config('agenda.timezone'))->toString();
        $context = new ToolContext(CarbonImmutable::now(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : config('agenda.timezone')));
        $proposed = [];

        // All of it, or nothing: if one cannot go (a person not found), none is sent.
        foreach ($messages as $message) {
            $result = $propose->handle($request->user(), $message, $context);

            if (isset($result->output['error'])) {
                array_walk($proposed, fn (PendingAction $action) => $actions->cancel($action));

                return response()->json(['message' => $result->output['error']], 422);
            }

            $proposed[] = $request->user()->pendingActions()->findOrFail($result->output['pending_action_id']);
        }

        return response()->json([
            'actions' => array_map(fn (PendingAction $action): array => $actions->confirm($action)->toState(), $proposed),
        ]);
    }
}
