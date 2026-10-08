<?php

namespace App\Connectors\Chat;

/**
 * Messages of a conversation to show: its latest, a thread, or the context
 * around one message.
 */
final readonly class ChatView
{
    /**
     * @param  list<ChatMessage>  $messages  Oldest first.
     */
    public function __construct(
        public ChatConversation $conversation,
        public array $messages,
        public ?string $focusRef = null,
        public ?string $threadRef = null,
    ) {}
}
