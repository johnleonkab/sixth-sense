<?php

namespace App\Comms\Backfill;

use App\Comms\Events\EventType;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Weak labels from what the user did after an incoming message.
 * A reply, a message to the author, or a ticket, event, or file that
 * points at it is acted. Anything else is ignored. No model.
 */
class LabelMiner
{
    /**
     * Ticket moves, created events, and shared files.
     *
     * @var list<string>
     */
    private const array ACTION_KINDS = [
        'status',
        'transition',
        'ticket_moved',
        'shipped',
        'merged',
        'published',
        'event_created',
        'file_shared',
    ];

    /**
     * @return array{acted: int, ignored: int}
     */
    public function run(User $user): array
    {
        $days = (int) config('comms.backfill.label_reply_days');
        $incoming = CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('direction', 'in')
            ->whereNull('deleted_at')
            ->with('author')
            ->orderBy('id')
            ->get()
            ->filter(fn (CommsMessage $message): bool => $this->human($message))
            ->values();
        $outgoing = CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('direction', 'out')
            ->whereNull('deleted_at')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $actions = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::SourceChanged)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $acted = 0;
        $ignored = 0;

        foreach ($incoming as $message) {
            $evidence = $this->evidence($message, $outgoing, $actions, $days);
            $this->write($user, $message, $evidence === [] ? 'ignored' : 'acted', $evidence);
            $evidence === [] ? $ignored++ : $acted++;
        }

        return ['acted' => $acted, 'ignored' => $ignored];
    }

    /**
     * @param  Collection<int, CommsMessage>  $outgoing
     * @param  Collection<int, CommsEvent>  $actions
     * @return list<int>
     */
    private function evidence(CommsMessage $message, Collection $outgoing, Collection $actions, int $days): array
    {
        $until = $message->occurred_at->addDays($days);
        $ids = [];

        foreach ($outgoing as $reply) {
            if ($reply->occurred_at->lte($message->occurred_at) || $reply->occurred_at->gt($until)) {
                continue;
            }

            if ($this->repliesTo($message, $reply)) {
                $ids[] = $reply->event_id;
            }
        }

        foreach ($actions as $action) {
            if ($action->occurred_at->lte($message->occurred_at) || $action->occurred_at->gt($until)) {
                continue;
            }

            if ($this->references($message, $action)) {
                $ids[] = $action->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function human(CommsMessage $message): bool
    {
        if ($message->author === null || $message->author->is_bot) {
            return false;
        }

        return ($message->flags['automated'] ?? false) !== true;
    }

    private function repliesTo(CommsMessage $message, CommsMessage $reply): bool
    {
        $sameSource = $reply->source === $message->source;
        $sameChannel = $sameSource && filled($message->channel_ref) && $reply->channel_ref === $message->channel_ref;
        $sameThread = $sameSource && filled($message->thread_ref) && $reply->thread_ref === $message->thread_ref;

        if ($sameChannel || $sameThread) {
            return true;
        }

        $recipients = array_map(intval(...), $reply->recipient_person_ids ?? []);

        return $message->author_person_id !== null && in_array($message->author_person_id, $recipients, true);
    }

    private function references(CommsMessage $message, CommsEvent $event): bool
    {
        $data = $event->normalized ?? [];

        if (! in_array($data['kind'] ?? '', self::ACTION_KINDS, true)) {
            return false;
        }

        $ref = (string) ($data['ref'] ?? '');
        $haystack = strtoupper($ref.' '.(string) ($data['text'] ?? ''));

        if ($message->external_id !== '' && str_contains($haystack, strtoupper($message->external_id))) {
            return true;
        }

        $source = $message->source->value;

        if (filled($message->channel_ref) && ($ref === $message->channel_ref || $ref === $source.':'.$message->channel_ref)) {
            return true;
        }

        if (filled($message->thread_ref) && ($ref === $message->thread_ref || $ref === $source.':'.$message->thread_ref)) {
            return true;
        }

        foreach ($this->ticketKeys($message->text) as $key) {
            if (str_contains($haystack, $key)) {
                return true;
            }
        }

        return $event->causation_id !== null && $event->causation_id === $message->event_id;
    }

    /**
     * @return list<string>
     */
    private function ticketKeys(string $text): array
    {
        preg_match_all('/\b[A-Z][A-Z0-9]+-\d+\b/', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @param  list<int>  $evidence
     */
    private function write(User $user, CommsMessage $message, string $label, array $evidence): void
    {
        $existing = CommsGoldenLabel::query()
            ->whereBelongsTo($user)
            ->where('kind', 'weak_label')
            ->where('message_id', $message->id)
            ->first();
        $values = [
            'label' => $label,
            'evidence_event_ids' => $evidence,
            'people' => $message->author_person_id === null ? [] : ['person:'.$message->author_person_id],
            'topics' => [],
            'source_event_id' => $message->event_id,
        ];

        if ($existing instanceof CommsGoldenLabel) {
            $existing->fill($values)->save();

            return;
        }

        CommsGoldenLabel::query()->create([
            'user_id' => $user->id,
            'kind' => 'weak_label',
            'message_id' => $message->id,
            ...$values,
        ]);
    }
}
