<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Chat\Chats;
use App\Models\PendingAction;

class ReactToChatMessage implements ActionHandler
{
    public function __construct(private Chats $chats) {}

    public function execute(PendingAction $action): array
    {
        $this->chats->react($action->user, $action->payload['message_id'], $action->payload['reaction']);

        return ['reacted' => $action->payload['reaction']];
    }
}
