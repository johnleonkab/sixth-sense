<?php

namespace App\Comms\Windows;

use App\Models\Comms\CommsMessage;

/**
 * The conversation a message belongs to. A real thread groups by its thread
 * ref. A direct message groups by the channel id that starts with D.
 * Everything else groups by the channel.
 */
final readonly class ConversationGroup
{
    public function __construct(public string $ref, public string $kind) {}

    public static function of(CommsMessage $message): self
    {
        $thread = $message->thread_ref;
        $colon = strrpos($message->external_id, ':');
        $own = $colon === false ? $message->external_id : substr($message->external_id, $colon + 1);
        $threaded = is_string($thread) && $thread !== '' && $thread !== $own && $thread !== $message->channel_ref;

        if ($threaded) {
            return new self($thread, 'thread');
        }

        $channel = (string) $message->channel_ref;

        return new self($channel, str_starts_with($channel, 'D') ? 'dm' : 'channel');
    }
}
