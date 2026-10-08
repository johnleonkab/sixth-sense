<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\OutgoingEmail;
use App\Models\PendingAction;

class SendEmail implements ActionHandler
{
    public function __construct(private Outbox $outbox) {}

    public function execute(PendingAction $action): array
    {
        $email = OutgoingEmail::fromArray($action->payload['email']);
        $sentRef = $this->outbox->send($action->user, $email);

        return ['sent_ref' => $sentRef, 'url' => $this->outbox->urlFor($action->user, $email, $sentRef)];
    }
}
