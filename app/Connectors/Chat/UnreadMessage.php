<?php

namespace App\Connectors\Chat;

/**
 * Something unread that matters: a direct message or a mention.
 */
final readonly class UnreadMessage
{
    /**
     * @param  'dm'|'mention'  $reason
     * @param  int  $count  Unread messages in that conversation, for direct messages.
     */
    public function __construct(
        public ChatMessage $message,
        public string $reason,
        public int $count = 1,
    ) {}
}
