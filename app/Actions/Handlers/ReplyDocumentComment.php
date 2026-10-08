<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Docs\Documents;
use App\Models\PendingAction;

class ReplyDocumentComment implements ActionHandler
{
    public function __construct(private Documents $documents) {}

    public function execute(PendingAction $action): array
    {
        $replyId = $this->documents->replyToComment(
            $action->user,
            $action->payload['ref'],
            $action->payload['comment_id'],
            $action->payload['content'],
            (bool) ($action->payload['resolve'] ?? false),
        );

        return ['reply_id' => $replyId, 'url' => $action->payload['url'] ?? null];
    }
}
