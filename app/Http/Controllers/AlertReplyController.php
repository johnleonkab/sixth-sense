<?php

namespace App\Http\Controllers;

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Connectors\Chat\ChatReactions;
use App\Connectors\Chat\Chats;
use App\Models\InboxItem;
use App\Models\PendingAction;
use App\Models\User;
use App\Voice\Tools\Toolbox;
use App\Voice\Tools\ToolContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Send what an alert proposes, with one tap: the tap is the "sí". The reply
 * (in its Slack thread or conversation, a reaction, or to the email's sender
 * or everyone on it), in Slack the message to pass on to someone else, and the
 * call or task proposed for the calendar, go out once the undo window closes,
 * as every confirmed action does. What the person unticks is left out.
 */
class AlertReplyController extends Controller
{
    public function __construct(private Chats $chats) {}

    public function __invoke(Request $request, int $item, Toolbox $toolbox, Actions $actions): JsonResponse
    {
        $alert = $request->user()->inboxItems()->findOrFail($item);
        $edits = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'relay_text' => ['nullable', 'string', 'max:4000'],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['string', 'in:reply,relay,task'],
        ]);
        $excluded = $edits['exclude'] ?? [];
        $messages = $alert->status === AlertStatus::Pending ? $this->messages($request->user(), $alert, $edits, $excluded) : [];
        $booking = $alert->status === AlertStatus::Pending && ! in_array('task', $excluded, true) ? $this->booking($alert) : null;

        abort_if($messages === [] && $booking === null, 422, 'Este aviso no tiene nada que enviar.');

        $timezone = $request->string('timezone', config('agenda.timezone'))->toString();
        $context = new ToolContext(CarbonImmutable::now(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : config('agenda.timezone')));
        $proposed = [];

        // All of it, or nothing: if one cannot go (a person not found), none is sent.
        foreach ($messages as [$tool, $arguments, $original]) {
            $result = $toolbox->run($toolbox->find($tool), $request->user(), $arguments, $context);

            if (isset($result->output['error'])) {
                array_walk($proposed, fn (PendingAction $action) => $actions->cancel($action));

                return response()->json(['message' => $result->output['error']], 422);
            }

            // Recorded against this alert, with what was proposed: an edit shows up as one.
            $action = $request->user()->pendingActions()->findOrFail($result->output['pending_action_id']);
            $action->update(['payload' => [...$action->payload, 'inbox_item_id' => $alert->id, ...($original !== null ? ['proposed_text' => $original] : [])]]);
            $proposed[] = $action;
        }

        if ($booking !== null) {
            $proposed[] = $booking;
        }

        return response()->json([
            'actions' => array_map(fn (PendingAction $action): array => $actions->confirm($action)->toState(), $proposed),
        ]);
    }

    /**
     * The call or task the scheduler proposed for this alert, while it waits for the yes.
     */
    private function booking(InboxItem $alert): ?PendingAction
    {
        $action = $alert->agendaTask?->pendingAction;

        return $action !== null && $action->status === ActionStatus::Pending ? $action : null;
    }

    /**
     * What the proposal would send, as the tools that propose each message,
     * with what was proposed (the person may have edited it before sending).
     *
     * @param  array{text?: ?string, relay_text?: ?string}  $edits
     * @param  list<string>  $excluded  What the person unticked: reply, relay.
     * @return list<array{string, array<string, mixed>, ?string}>
     */
    private function messages(User $user, InboxItem $alert, array $edits = [], array $excluded = []): array
    {
        $reply = $alert->proposal['reply'] ?? null;
        $reaction = $alert->proposal['reaction']['name'] ?? null;
        $redirect = $alert->proposal['redirect'] ?? null;
        $replyText = in_array('reply', $excluded, true) ? null : (filled($edits['text'] ?? null) ? $edits['text'] : ($reply['text'] ?? null));
        $relayText = in_array('relay', $excluded, true) ? null : (filled($edits['relay_text'] ?? null) ? $edits['relay_text'] : ($redirect['text'] ?? null));
        $messages = [];

        if ($alert->source === AlertSource::Gmail && filled($replyText)) {
            $messages[] = ['propose_email_reply', ['email_id' => $alert->external_id, 'body' => $replyText, 'reply_all' => ($reply['to'] ?? 'sender') === 'all'], $reply['text'] ?? null];
        }

        if ($alert->source !== AlertSource::Slack) {
            return $messages;
        }

        if (filled($replyText)) {
            // In its thread, or in the conversation it came from.
            $messages[] = ['propose_slack_message', ['text' => $replyText, ...in_array($reply['to'] ?? 'thread', ['conversation', 'all'], true)
                ? ['to' => Str::beforeLast($alert->external_id, ':')]
                : ['reply_to' => $alert->external_id]], $reply['text'] ?? null];
        } elseif (! in_array('reply', $excluded, true) && isset(ChatReactions::NAMES[$reaction ?? ''])) {
            // A reaction, or the emoji in its thread while the account may not react.
            $messages[] = $this->chats->canReact($user, $alert->external_id)
                ? ['propose_slack_reaction', ['message_id' => $alert->external_id, 'reaction' => $reaction], null]
                : ['propose_slack_message', ['text' => ChatReactions::emoji($reaction), 'reply_to' => $alert->external_id], null];
        }

        if (filled($relayText) && filled($redirect['to'] ?? null)) {
            $messages[] = ['propose_slack_message', ['to' => $redirect['to'], 'text' => $relayText], $redirect['text'] ?? null];
        }

        return $messages;
    }
}
