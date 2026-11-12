<?php

namespace App\Comms;

use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;

/**
 * Which sources have left the old alert brain. Off by default.
 */
class Pipeline
{
    public function enabled(string $source): bool
    {
        return in_array($source, ['slack', 'gmail'], true) && (bool) config('comms.pipeline.'.$source, false);
    }

    /**
     * Slack or Gmail when this notification belongs to a cut-over source.
     */
    public function sourceOf(CommsNotification $notification): ?string
    {
        $ids = array_values(array_filter(
            $notification->source_event_ids ?? [],
            fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
        ));

        if ($ids !== []) {
            $sources = CommsEvent::query()->whereIn('id', $ids)->pluck('source');

            foreach ($sources as $source) {
                $value = $source instanceof Source ? $source->value : (string) $source;

                if (in_array($value, ['slack', 'gmail'], true)) {
                    return $value;
                }
            }
        }

        $intentId = match ($notification->subject_type) {
            'intent' => $notification->subject_id,
            'proposal' => CommsProposal::query()->find($notification->subject_id)?->intent_id,
            'question' => CommsQuestion::query()->find($notification->subject_id)?->intent_id,
            default => null,
        };

        if (is_int($intentId)) {
            $fromIntent = $this->intentSource($intentId);

            if ($fromIntent !== null) {
                return $fromIntent;
            }
        }

        if ($notification->subject_type !== 'proposal' || $notification->subject_id === null) {
            return null;
        }

        return match (CommsProposal::query()->find($notification->subject_id)?->action_class) {
            ActionClass::SendSlack => 'slack',
            ActionClass::SendEmailReply, ActionClass::SendEmailNew => 'gmail',
            default => null,
        };
    }

    private function intentSource(int $intentId): ?string
    {
        $source = CommsMessage::query()
            ->whereIn('id', CommsConversationLink::query()->where('intent_id', $intentId)->pluck('message_id'))
            ->value('source');

        if ($source instanceof Source) {
            return $source->value;
        }

        return is_string($source) && $source !== '' ? $source : null;
    }
}
