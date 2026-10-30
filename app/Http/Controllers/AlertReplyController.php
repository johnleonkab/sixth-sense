<?php

namespace App\Http\Controllers;

use App\Actions\Actions;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Voice\Tools\Slack\ProposeSlackMessage;
use App\Voice\Tools\ToolContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Send the reply an alert proposes, with one tap: the tap is the "sí". It goes
 * out once the undo window closes, as every confirmed action does.
 */
class AlertReplyController extends Controller
{
    public function __invoke(Request $request, int $item, ProposeSlackMessage $propose, Actions $actions): JsonResponse
    {
        $alert = $request->user()->inboxItems()->findOrFail($item);
        $reply = $alert->proposal['reply'] ?? null;

        abort_unless($alert->source === AlertSource::Slack && $alert->status === AlertStatus::Pending && filled($reply['text'] ?? null), 422, 'Este aviso no tiene una respuesta de Slack que enviar.');

        $timezone = $request->string('timezone', config('agenda.timezone'))->toString();
        $result = $propose->handle($request->user(), [
            'text' => $reply['text'],
            // In its thread, or in the conversation it came from.
            ...in_array($reply['to'] ?? 'thread', ['conversation', 'all'], true)
                ? ['to' => Str::beforeLast($alert->external_id, ':')]
                : ['reply_to' => $alert->external_id],
        ], new ToolContext(CarbonImmutable::now(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : config('agenda.timezone'))));

        if (isset($result->output['error'])) {
            return response()->json(['message' => $result->output['error']], 422);
        }

        $action = $actions->confirm($request->user()->pendingActions()->findOrFail($result->output['pending_action_id']));

        return response()->json(['action' => $action->toState()]);
    }
}
