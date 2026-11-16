<?php

namespace App\Comms\Surface;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Models\Comms\CommsCardView;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One intent, collapsed for the list and open for the card (proposal 7.3).
 * Opening appends user.viewed and stores when the user last looked.
 */
class IntentCard
{
    /**
     * @var array<string, string>
     */
    private const QUESTIONS = [
        'kind' => '¿Qué tipo de petición es?',
        'requesters' => '¿Quién lo pide?',
        'responders' => '¿Quién debería hacerlo?',
        'deadline' => '¿Para cuándo?',
    ];

    public function __construct(
        private EventLog $log,
        private PlanChecklist $plan,
    ) {}

    /**
     * @return array{collapsed: array<string, mixed>, open: array<string, mixed>}|null
     */
    public function show(User $user, CommsIntent $intent): ?array
    {
        if ($intent->user_id !== $user->id) {
            return null;
        }

        $now = CarbonImmutable::now();
        $unclear = $this->unclear($intent);
        $participants = $intent->participants()->with('person')->orderBy('id')->get();
        $names = ['requester' => [], 'responder' => []];
        $selfResponds = false;
        $selfRequests = false;

        foreach ($participants as $participant) {
            $person = $participant->person;
            $name = $person?->display_name;

            if ($person?->relation === Relation::Self) {
                $selfResponds = $selfResponds || $participant->role === 'responder';
                $selfRequests = $selfRequests || $participant->role === 'requester';
            }

            if (is_string($name) && $name !== '' && isset($names[$participant->role])) {
                $names[$participant->role][] = $name;
            }
        }

        $links = $intent->links()->with('message.author')->orderBy('id')->get();
        $rows = [];

        foreach ($links as $link) {
            $message = $link->message;

            if (! $message instanceof CommsMessage) {
                continue;
            }

            $rows[] = ['operation' => $link->operation, 'message' => $message];
        }

        usort($rows, function (array $left, array $right): int {
            $byTime = $left['message']->occurred_at <=> $right['message']->occurred_at;

            return $byTime !== 0 ? $byTime : $left['message']->id <=> $right['message']->id;
        });

        $conversation = [];
        $channels = [];
        $places = [];

        foreach ($rows as $row) {
            /** @var CommsMessage $message */
            $message = $row['message'];
            $source = $message->source->value;
            $place = $source.':'.($message->channel_ref ?? '');

            if (! in_array($source, $channels, true)) {
                $channels[] = $source;
            }

            $places[$place] = true;
            $conversation[] = [
                'source' => $source,
                'channel' => $message->channel_ref,
                'at' => $message->occurred_at?->toIso8601String(),
                'author' => $message->author?->display_name,
                'text' => $message->text,
            ];
        }

        $quote = null;

        foreach ($rows as $row) {
            if ($row['operation'] === 'open') {
                $quote = $row['message']->text;

                break;
            }
        }

        $quote ??= $conversation[0]['text'] ?? null;
        $pending = CommsProposal::query()
            ->where('intent_id', $intent->id)
            ->where('status', ProposalStatus::Pending)
            ->orderBy('id')
            ->get();
        $seenAt = CommsCardView::query()
            ->whereBelongsTo($user)
            ->where('intent_id', $intent->id)
            ->value('seen_at');
        $seenAt = $seenAt instanceof CarbonInterface ? CarbonImmutable::instance($seenAt) : (is_string($seenAt) ? CarbonImmutable::parse($seenAt) : null);
        $changedAt = $intent->changed_at instanceof CarbonInterface ? CarbonImmutable::instance($intent->changed_at) : null;
        $changedSince = $changedAt !== null && $seenAt !== null && $changedAt->greaterThan($seenAt)
            ? $intent->changed_summary
            : null;
        $changedSince = is_string($changedSince) && $changedSince !== '' ? $changedSince : null;

        return [
            'collapsed' => [
                'id' => $intent->id,
                'what' => $intent->what,
                'topic' => $intent->topic_subject,
                'channels' => $channels,
                'who' => $this->who($unclear, $names['requester'], $names['responder'], $selfResponds),
                'deadline' => $unclear['deadline'] ?? $intent->deadline?->toDateString(),
                'pill' => $this->pill($intent, $now, $pending->count(), $changedAt, $seenAt, $selfResponds, $selfRequests),
            ],
            'open' => [
                'what' => $intent->what,
                'requesters' => isset($unclear['requesters']) ? [] : $names['requester'],
                'responders' => isset($unclear['responders']) ? [] : $names['responder'],
                'topic' => $intent->topic_subject,
                'deadline' => isset($unclear['deadline']) ? null : $intent->deadline?->toDateString(),
                'unclear' => $unclear,
                'changed_since' => $changedSince,
                'plan' => $this->plan->for($intent),
                'actions' => $pending->map(fn (CommsProposal $proposal): array => [
                    'id' => $proposal->id,
                    'action' => $proposal->action_class->value,
                    'status' => $proposal->status->value,
                ])->all(),
                'evidence' => [
                    'quote' => $quote,
                    'message_count' => count($conversation),
                    'channel_count' => count($places),
                    'conversation' => $conversation,
                ],
            ],
        ];
    }

    /**
     * @return array{collapsed: array<string, mixed>, open: array<string, mixed>}|null
     */
    public function open(User $user, CommsIntent $intent): ?array
    {
        $card = $this->show($user, $intent);

        if ($card === null) {
            return null;
        }

        $seen = CarbonImmutable::now();
        $event = $this->log->append(
            user: $user,
            type: EventType::UserViewed,
            source: Source::User,
            externalId: 'card:'.$intent->id,
            payload: null,
            normalized: [
                'intent_id' => $intent->id,
                'seen_at' => $seen->toIso8601String(),
            ],
            causation: null,
            occurredAt: $seen,
            revision: (string) hrtime(true),
            dispatch: false,
        );
        $this->apply($event);

        return $card;
    }

    /**
     * Project a stored view. Replay and the live pipeline call the same method.
     */
    public function apply(CommsEvent $event): void
    {
        if ($event->type !== EventType::UserViewed || $event->user_id === null) {
            return;
        }

        $intentId = $event->normalized['intent_id'] ?? null;

        if (! is_int($intentId) && ! (is_string($intentId) && ctype_digit($intentId))) {
            return;
        }

        $seen = $event->normalized['seen_at'] ?? null;
        $at = is_string($seen) ? CarbonImmutable::parse($seen) : CarbonImmutable::parse($event->occurred_at);

        CommsCardView::query()->updateOrCreate(
            ['user_id' => $event->user_id, 'intent_id' => (int) $intentId],
            ['seen_at' => $at],
        );
    }

    /**
     * @return array<string, string>
     */
    private function unclear(CommsIntent $intent): array
    {
        $fields = is_array($intent->unclear_fields) ? $intent->unclear_fields : [];
        $questions = [];

        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $questions[$field] = self::QUESTIONS[$field] ?? '¿Qué falta aquí?';
        }

        return $questions;
    }

    /**
     * @param  array<string, string>  $unclear
     * @param  list<string>  $requesters
     * @param  list<string>  $responders
     */
    private function who(array $unclear, array $requesters, array $responders, bool $selfResponds): string
    {
        if (isset($unclear['responders'])) {
            return $unclear['responders'];
        }

        if ($selfResponds && $requesters !== []) {
            $joined = $this->join($requesters);

            return count($requesters) > 1 ? "{$joined} esperan tu respuesta" : "{$joined} espera tu respuesta";
        }

        if ($responders !== []) {
            return 'Esperas a '.$this->join($responders);
        }

        return 'Te toca';
    }

    /**
     * @param  list<string>  $names
     */
    private function join(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).' y '.$last;
    }

    private function pill(
        CommsIntent $intent,
        CarbonImmutable $now,
        int $pending,
        ?CarbonImmutable $changedAt,
        ?CarbonImmutable $seenAt,
        bool $selfResponds,
        bool $selfRequests,
    ): string {
        if ($pending > 0) {
            return "Aprobar {$pending}";
        }

        $questions = CommsQuestion::query()
            ->where('intent_id', $intent->id)
            ->whereNull('answered_event_id')
            ->count();

        if ($questions > 0) {
            return 'Tu decisión';
        }

        if ($intent->state === 'open' && $intent->acknowledged_at !== null) {
            return 'Confirmar el resultado';
        }

        if ($changedAt !== null && $seenAt !== null && $changedAt->greaterThan($seenAt)) {
            return 'Actualizado '.$this->ago($changedAt, $now);
        }

        $group = BallHolder::group([
            'state' => (string) $intent->state,
            'settled_at' => $intent->settled_at,
            'possible_ask' => (bool) $intent->possible_ask,
            'snoozed_until' => $intent->snoozed_until,
            'user_is_responder' => $selfResponds,
            'user_is_requester' => $selfRequests,
            'pending_proposals' => 0,
            'open_questions' => 0,
            'executed_actions' => CommsProposal::query()->where('intent_id', $intent->id)->where('status', ProposalStatus::Executed)->count(),
            'active_runs' => CommsPlaybookRun::query()->where('intent_id', $intent->id)->where('state', '!=', 'done')->count(),
            'waiting_watchers' => CommsWatcher::query()->where('intent_id', $intent->id)->where('state', 'active')->count(),
        ], $now);

        return $group->label();
    }

    private function ago(CarbonImmutable $changedAt, CarbonImmutable $now): string
    {
        $minutes = intdiv(abs($now->getTimestamp() - $changedAt->getTimestamp()), 60);

        if ($minutes < 1) {
            return 'ahora';
        }

        if ($minutes < 60) {
            return "hace {$minutes} min";
        }

        if ($minutes < 1440) {
            return 'hace '.intdiv($minutes, 60).' h';
        }

        return 'hace '.intdiv($minutes, 1440).' d';
    }
}
