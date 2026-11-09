<?php

namespace App\Comms\Gate\WriteTools;

use App\Connectors\Chat\Chats;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * Sends one Slack message. A second call with the same external id does not send again.
 */
class SendSlackMessage
{
    public function __construct(private Chats $chats) {}

    public function send(User $user, CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return $proposal->external_id;
        }

        $payload = $proposal->payload;

        return $this->chats->send($user, new OutgoingChatMessage(
            to: (string) $payload['to'],
            toLabel: (string) ($payload['to_label'] ?? $payload['to']),
            text: (string) ($payload['text'] ?? ''),
            threadRef: isset($payload['thread_ref']) ? (string) $payload['thread_ref'] : null,
        ));
    }
}
