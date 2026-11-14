<?php

namespace App\Comms\Cutover;

use App\Alerts\AlertFeed;
use App\Alerts\AlertSource;
use App\Comms\Gate\ActionClass;
use App\Comms\People\Relation;
use App\Comms\Pipeline;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsSignal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The home list: old alerts for sources still on the old brain, and
 * communications notifications for sources that have been cut over.
 */
class HomeFeed
{
    public function __construct(private AlertFeed $alerts, private Pipeline $pipeline) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(User $user): array
    {
        $kept = array_values(array_filter(
            $this->alerts->for($user),
            fn (array $alert): bool => ! $this->pipeline->enabled((string) $alert['source']),
        ));
        $items = [...$kept, ...$this->cards($user)];

        usort($items, fn (array $left, array $right): int => AlertFeed::priority($left) <=> AlertFeed::priority($right));

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cards(User $user): array
    {
        $cards = [];
        $notifications = $this->visible(CommsNotification::query()
            ->whereBelongsTo($user)
            ->whereNull('dismissed_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get());

        foreach ($notifications as $notification) {
            $source = $this->pipeline->sourceOf($notification);

            if ($source === null || ! $this->pipeline->enabled($source)) {
                continue;
            }

            $cards[] = $this->card($notification);
        }

        return $cards;
    }

    /**
     * One row per intent. A draft or a follow-up updates the alert that is
     * already there; a second action of a different kind keeps its own row.
     *
     * @param  Collection<int, CommsNotification>  $notifications
     * @return Collection<int, CommsNotification>
     */
    private function visible(Collection $notifications): Collection
    {
        $proposalIds = $notifications->where('subject_type', 'proposal')->pluck('subject_id')->filter()->all();
        $questionIds = $notifications->where('subject_type', 'question')->pluck('subject_id')->filter()->all();
        $proposals = $proposalIds === []
            ? collect()
            : CommsProposal::query()->whereIn('id', $proposalIds)->get(['id', 'intent_id', 'action_class'])->keyBy('id');
        $questionIntents = $questionIds === []
            ? collect()
            : CommsQuestion::query()->whereIn('id', $questionIds)->pluck('intent_id', 'id');
        $intentId = function (CommsNotification $notification) use ($proposals, $questionIntents): ?int {
            $id = match ($notification->subject_type) {
                'intent' => $notification->subject_id,
                'proposal' => $proposals->get($notification->subject_id)?->intent_id,
                'question' => $questionIntents->get($notification->subject_id),
                default => null,
            };

            return is_numeric($id) ? (int) $id : null;
        };
        $withAction = [];

        foreach ($notifications as $notification) {
            $id = $intentId($notification);

            if ($id !== null && in_array($notification->kind, ['proposal', 'question'], true)) {
                $withAction[$id] = true;
            }
        }

        $seenSignal = [];
        $seenProposal = [];
        $seenQuestion = [];
        $kept = [];

        foreach ($notifications as $notification) {
            $id = $intentId($notification);

            if ($id === null) {
                $kept[] = $notification;

                continue;
            }

            if ($notification->kind === 'signal') {
                if (isset($withAction[$id]) || isset($seenSignal[$id])) {
                    continue;
                }

                $seenSignal[$id] = true;
                $kept[] = $notification;

                continue;
            }

            if ($notification->kind === 'proposal') {
                $class = $proposals->get($notification->subject_id)?->action_class;
                $slot = $id.':'.($class instanceof ActionClass ? $class->value : (string) $class);

                if (isset($seenProposal[$slot])) {
                    continue;
                }

                $seenProposal[$slot] = true;
                $kept[] = $notification;

                continue;
            }

            if ($notification->kind === 'question') {
                if (isset($seenQuestion[$id])) {
                    continue;
                }

                $seenQuestion[$id] = true;
                $kept[] = $notification;

                continue;
            }

            $kept[] = $notification;
        }

        return new Collection($kept);
    }

    /**
     * @return array<string, mixed>
     */
    public function card(CommsNotification $notification): array
    {
        $source = $this->pipeline->sourceOf($notification) ?? 'slack';
        $proposal = $notification->subject_type === 'proposal'
            ? CommsProposal::query()->find($notification->subject_id)
            : null;
        $question = $notification->subject_type === 'question'
            ? CommsQuestion::query()->find($notification->subject_id)
            : null;
        $sourceMessage = $notification->subject_type === null ? $this->sourceMessage($notification) : null;
        $signal = $sourceMessage === null ? null : CommsSignal::query()
            ->where('user_id', $notification->user_id)
            ->where('message_id', $sourceMessage->id)
            ->latest('id')
            ->first();
        $intent = $this->resolveIntent($notification, $proposal, $question, $signal);
        $linkedMessages = $this->linkedMessages($intent);
        $actor = $this->actorName($intent, $linkedMessages, $proposal, $sourceMessage);
        $text = $this->text($notification, $intent, $proposal, $question, $signal, $sourceMessage);
        $proposalSummary = $notification->kind === 'proposal'
            ? ($this->proposalSummary($proposal) ?? $text)
            : null;
        $waiting = in_array($notification->kind, ['question', 'proposal'], true)
            || ($intent !== null && $intent->state !== 'settled');
        $pending = $notification->dismissed_at === null && $waiting;
        $received = ($notification->created_at ?? now())->toIso8601String();

        return [
            'id' => -$notification->id,
            'source' => $source,
            'source_label' => AlertSource::from($source)->label(),
            'actor_name' => $actor ?? 'sixth-sense',
            'title' => Str::limit($text, 120, ''),
            'why' => $this->why($notification, $intent, $question),
            'received_at' => $received,
            'external_id' => 'comms:'.$notification->id,
            'external_url' => null,
            'account' => null,
            'starts_at' => null,
            'meeting_url' => null,
            'event_ref' => null,
            'status' => $notification->dismissed_at !== null ? 'dismissed' : ($pending ? 'pending' : 'notice'),
            'state' => $notification->dismissed_at !== null ? 'dismissed' : match ($notification->kind) {
                'question' => 'question',
                'proposal' => 'ready',
                default => $notification->urgency === 'now' ? 'important' : 'routine',
            },
            'resolved_at' => $notification->dismissed_at?->toIso8601String(),
            'resolution' => $notification->dismissed_at !== null ? 'dismissed' : null,
            'deadline' => $intent?->deadline?->toDateString(),
            'blocking' => $notification->urgency === 'now',
            'quiet' => $notification->urgency !== 'now',
            'messages' => $this->formatMessages($linkedMessages, $sourceMessage) ?: [[
                'id' => 'comms:'.$notification->id,
                'from' => $actor ?? 'sixth-sense',
                'text' => $text,
                'at' => $received,
            ]],
            'proposal' => $proposalSummary !== null ? ['summary' => Str::limit($proposalSummary, 140, '')] : null,
            'task' => null,
            'comms' => true,
        ];
    }

    public function intentFor(CommsNotification $notification): ?CommsIntent
    {
        $proposal = $notification->subject_type === 'proposal'
            ? CommsProposal::query()->find($notification->subject_id)
            : null;
        $question = $notification->subject_type === 'question'
            ? CommsQuestion::query()->find($notification->subject_id)
            : null;
        $sourceMessage = $notification->subject_type === null ? $this->sourceMessage($notification) : null;
        $signal = $sourceMessage === null ? null : CommsSignal::query()
            ->where('user_id', $notification->user_id)
            ->where('message_id', $sourceMessage->id)
            ->latest('id')
            ->first();

        return $this->resolveIntent($notification, $proposal, $question, $signal);
    }

    public function actorFor(CommsNotification $notification): ?string
    {
        $proposal = $notification->subject_type === 'proposal'
            ? CommsProposal::query()->find($notification->subject_id)
            : null;
        $sourceMessage = $notification->subject_type === null ? $this->sourceMessage($notification) : null;
        $intent = $this->intentFor($notification);

        return $this->actorName($intent, $this->linkedMessages($intent), $proposal, $sourceMessage);
    }

    public function sourceMessage(CommsNotification $notification): ?CommsMessage
    {
        $eventIds = array_values(array_filter(
            $notification->source_event_ids ?? [],
            fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
        ));

        if ($eventIds === []) {
            return null;
        }

        $direct = CommsMessage::query()
            ->where('user_id', $notification->user_id)
            ->whereIn('event_id', $eventIds)
            ->with('author')
            ->latest('id')
            ->first();

        if ($direct !== null) {
            return $direct;
        }

        foreach (CommsEvent::query()->whereIn('id', $eventIds)->get(['source', 'external_id']) as $event) {
            if ($event->external_id === null || $event->external_id === '') {
                continue;
            }

            $matched = CommsMessage::query()
                ->where('user_id', $notification->user_id)
                ->where('source', $event->source)
                ->where('external_id', $event->external_id)
                ->with('author')
                ->latest('id')
                ->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    private function resolveIntent(
        CommsNotification $notification,
        ?CommsProposal $proposal,
        ?CommsQuestion $question,
        ?CommsSignal $signal,
    ): ?CommsIntent {
        $id = match ($notification->subject_type) {
            'intent' => $notification->subject_id,
            'proposal' => $proposal?->intent_id,
            'question' => $question?->intent_id,
            default => $signal?->intent_id,
        };

        return $id === null
            ? null
            : CommsIntent::query()
                ->where('user_id', $notification->user_id)
                ->with('participants.person')
                ->find($id);
    }

    /**
     * @param  Collection<int, CommsMessage>  $linkedMessages
     */
    private function actorName(
        ?CommsIntent $intent,
        Collection $linkedMessages,
        ?CommsProposal $proposal,
        ?CommsMessage $sourceMessage,
    ): ?string {
        if ($intent !== null) {
            $requester = $intent->participants
                ->where('role', 'requester')
                ->map(fn ($participant) => $participant->person)
                ->first(fn ($person): bool => $person !== null && $person->relation !== Relation::Self && filled($person->display_name));

            if ($requester !== null) {
                return (string) $requester->display_name;
            }

            $other = $intent->participants
                ->map(fn ($participant) => $participant->person)
                ->first(fn ($person): bool => $person !== null && $person->relation !== Relation::Self && filled($person->display_name));

            if ($other !== null) {
                return (string) $other->display_name;
            }

            $fromLinked = $linkedMessages
                ->reverse()
                ->first(fn (CommsMessage $message): bool => $message->author !== null && $message->author->relation !== Relation::Self && filled($message->author->display_name));

            if ($fromLinked !== null) {
                return (string) $fromLinked->author->display_name;
            }
        }

        if ($sourceMessage?->author !== null && $sourceMessage->author->relation !== Relation::Self && filled($sourceMessage->author->display_name)) {
            return (string) $sourceMessage->author->display_name;
        }

        if ($proposal !== null) {
            $toName = trim((string) ($proposal->payload['to_name'] ?? ''));

            if ($toName !== '') {
                return $toName;
            }
        }

        return null;
    }

    /**
     * Why it is on the list, in a line: by when, and whether it is urgent. Who
     * asks is already the card's actor.
     */
    private function why(CommsNotification $notification, ?CommsIntent $intent, ?CommsQuestion $question): string
    {
        if ($intent === null) {
            return match ($notification->kind) {
                'question' => 'Necesito que me lo confirmes.',
                'proposal' => 'Tengo una propuesta lista para enviar.',
                'batch' => 'Llevan días sin moverse.',
                default => $notification->urgency === 'now' ? 'Quiere tu atención.' : 'Para el resumen.',
            };
        }

        if ($this->userAsked($intent) && $question !== null && filled($question->text)) {
            $line = trim((string) $question->text);

            return ($intent->flags['urgent'] ?? false) === true && ! str_starts_with($line, 'Urgente.')
                ? 'Urgente. '.$line
                : $line;
        }

        $line = $intent->deadline !== null
            ? 'Lo necesita para el '.CarbonImmutable::parse($intent->deadline)->locale('es')->isoFormat('dddd D [de] MMMM').'.'
            : 'Espera tu respuesta.';

        return ($intent->flags['urgent'] ?? false) === true ? 'Urgente. '.$line : $line;
    }

    private function userAsked(CommsIntent $intent): bool
    {
        $requesters = $intent->participants->where('role', 'requester');

        return $requesters->isNotEmpty()
            && $requesters->every(fn ($participant): bool => $participant->person?->relation === Relation::Self);
    }

    /**
     * The messages the intent came from, oldest first.
     *
     * @return Collection<int, CommsMessage>
     */
    private function linkedMessages(?CommsIntent $intent): Collection
    {
        if ($intent === null) {
            return collect();
        }

        return $intent->links()
            ->with('message.author')
            ->latest('id')
            ->limit(5)
            ->get()
            ->reverse()
            ->map(fn ($link): ?CommsMessage => $link->message)
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, CommsMessage>  $linkedMessages
     * @return list<array{id: string, from: string, text: string, at: string}>
     */
    private function formatMessages(Collection $linkedMessages, ?CommsMessage $sourceMessage): array
    {
        $messages = $linkedMessages->isNotEmpty()
            ? $linkedMessages
            : ($sourceMessage !== null ? collect([$sourceMessage]) : collect());

        return $messages
            ->map(fn (CommsMessage $message): array => [
                'id' => 'comms-message:'.$message->id,
                'from' => (string) ($message->author->display_name ?? 'Alguien'),
                'text' => (string) $message->text,
                'at' => $message->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function proposalSummary(?CommsProposal $proposal): ?string
    {
        if ($proposal === null) {
            return null;
        }

        $title = trim((string) ($proposal->payload['title'] ?? ''));
        $text = trim((string) ($proposal->payload['text'] ?? ''));

        return match ($proposal->action_class) {
            ActionClass::NotionCreatePage => $title !== '' ? "Página en Notion: {$title}" : 'Crear página en Notion',
            ActionClass::CalendarCreate, ActionClass::CalendarUpdate => $title !== '' ? $title : ($text !== '' ? $text : 'Bloque en el calendario'),
            default => $text !== '' ? $text : ($title !== '' ? $title : null),
        };
    }

    private function text(
        CommsNotification $notification,
        ?CommsIntent $intent,
        ?CommsProposal $proposal,
        ?CommsQuestion $question,
        ?CommsSignal $signal,
        ?CommsMessage $sourceMessage,
    ): string {
        if ($intent !== null && filled($intent->what)) {
            return (string) $intent->what;
        }

        if ($signal !== null && filled($signal->summary)) {
            return (string) $signal->summary;
        }

        if ($question !== null && filled($question->text)) {
            return (string) $question->text;
        }

        $summary = $this->proposalSummary($proposal);

        if ($summary !== null) {
            return $summary;
        }

        if ($sourceMessage !== null && filled($sourceMessage->text)) {
            return (string) $sourceMessage->text;
        }

        return match ($notification->kind) {
            'question' => 'Hay una pregunta abierta.',
            'proposal' => 'Hay una propuesta sin enviar.',
            'batch' => '¿Siguen abiertas?',
            default => 'Hay algo nuevo.',
        };
    }
}
