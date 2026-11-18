<?php

namespace App\Http\Controllers;

use App\Actions\ActionStatus;
use App\Comms\Context\ContextWriter;
use App\Comms\Cutover\HomeFeed;
use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\QuestionDraft;
use App\Comms\Notify\Notifier;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shadow tab. Notifications are stored here and never pushed.
 */
class ShadowController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = CommsNotification::query()
            ->whereBelongsTo($request->user())
            ->whereNull('delivered_at')
            ->orderByDesc('id')
            ->get();

        $questions = CommsQuestion::query()
            ->whereBelongsTo($request->user())
            ->whereIn('id', $notifications->where('subject_type', 'question')->pluck('subject_id'))
            ->get()
            ->keyBy('id');
        $proposals = CommsProposal::query()
            ->whereBelongsTo($request->user())
            ->whereIn('id', $notifications->where('subject_type', 'proposal')->pluck('subject_id'))
            ->with('pendingAction')
            ->get()
            ->keyBy('id');

        return Inertia::render('shadow', [
            'items' => $notifications->map(function (CommsNotification $notification) use ($questions, $proposals): array {
                $question = $notification->subject_type === 'question'
                    ? $questions->get($notification->subject_id)
                    : null;
                $proposal = $notification->subject_type === 'proposal'
                    ? $proposals->get($notification->subject_id)
                    : null;

                return [
                    'id' => $notification->id,
                    'kind' => $notification->kind,
                    'urgency' => $notification->urgency,
                    'text' => $question?->text ?? ($proposal !== null ? (string) ($proposal->payload['text'] ?? '') : null),
                    'lines' => is_array($question?->context['intents'] ?? null)
                        ? array_values(array_map(
                            fn (mixed $row): string => is_array($row) ? (string) ($row['what'] ?? '') : '',
                            $question->context['intents'],
                        ))
                        : [],
                    'feedback' => $notification->feedback,
                    'seen' => $notification->seen_at !== null,
                    'question_id' => $question?->id,
                    'draft' => is_string($question?->context['draft'] ?? null) ? $question->context['draft'] : null,
                    'proposal' => $proposal === null ? null : [
                        'id' => $proposal->id,
                        'status' => $proposal->status->value,
                        'text' => (string) ($proposal->payload['text'] ?? ''),
                        'deny_reason' => $proposal->deny_reason,
                        'outcome' => $proposal->policy_outcome?->value,
                        'undo' => $proposal->pendingAction?->status === ActionStatus::Confirmed,
                    ],
                ];
            })->all(),
        ]);
    }

    public function draft(Request $request, QuestionDraft $drafts, CommsQuestion $question): RedirectResponse
    {
        abort_unless($question->user_id === $request->user()?->id, 404);

        $text = $request->validate([
            'draft' => ['required', 'string', 'max:4000'],
        ])['draft'];

        $drafts->save($request->user(), $question, $text);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Borrador guardado.']);

        return back();
    }

    public function send(Request $request, QuestionDraft $drafts, CommsQuestion $question): RedirectResponse
    {
        abort_unless($question->user_id === $request->user()?->id, 404);

        $proposal = $drafts->propose($request->user(), $question->refresh());

        abort_if($proposal === null, 422);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Propuesta lista.']);

        return back();
    }

    public function dismiss(Request $request, HomeFeed $feed, Notifier $notifier, CommsNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()?->id, 404);

        $notifier->dismissGroup($request->user(), $notification);

        return response()->json(['item' => $feed->card($notification->refresh())]);
    }

    public function feedback(Request $request, EventLog $log, ContextWriter $context, HomeFeed $feed, CommsNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()?->id, 404);

        $feedback = $request->validate([
            'feedback' => ['required', 'string', Rule::in(['useful', 'not_urgent', 'not_relevant', 'wrong'])],
        ])['feedback'];

        $log->append(
            user: $request->user(),
            type: EventType::UserAnswered,
            source: Source::User,
            externalId: 'feedback:'.$notification->key,
            payload: null,
            normalized: [
                'kind' => 'feedback',
                'notification_key' => $notification->key,
                'feedback' => $feedback,
            ],
            causation: null,
            occurredAt: now(),
            revision: (string) Str::uuid(),
        );

        $requester = $feed->actorFor($notification);
        $sentence = $requester === null ? null : match ($feedback) {
            'useful' => "Lo que pide {$requester} sí merece aviso.",
            'not_urgent' => "Lo que pide {$requester} no suele ser urgente: avisar sin interrumpir.",
            'not_relevant' => "Lo que escribe {$requester} no le importa: no avisar.",
            'wrong' => "Un aviso sobre lo que pidió {$requester} estaba mal entendido: leer el hilo antes de avisar.",
        };

        if ($sentence !== null) {
            $context->rule($request->user(), $sentence, 'judge', $requester, topic: 'shadow:'.Str::lower($requester), refs: ["notification:{$notification->key}"]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anotado.']);

        return back();
    }
}
