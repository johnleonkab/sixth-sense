<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Models\PendingAction;

class SendChatMessage implements ActionHandler
{
    public function __construct(private Chats $chats) {}

    public function execute(PendingAction $action): array
    {
        return ['sent_ref' => $this->chats->send($action->user, OutgoingChatMessage::fromArray($action->payload['message']))];
    }
}
