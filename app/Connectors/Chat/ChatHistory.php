<?php

namespace App\Connectors\Chat;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Chat messages older than a moment. Read only: it searches and never sends.
 */
class ChatHistory
{
    public function __construct(private Chats $chats) {}

    /**
     * @return list<ChatMessage>
     */
    public function before(User $user, string $term, CarbonImmutable $before): array
    {
        return array_values(array_filter(
            $this->chats->search($user, new ChatSearch(text: $term, before: $before, limit: 15)),
            fn (ChatMessage $message): bool => $message->sentAt->lessThan($before),
        ));
    }
}
