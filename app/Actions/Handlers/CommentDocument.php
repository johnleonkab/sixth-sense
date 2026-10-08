<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Docs\Documents;
use App\Models\PendingAction;

class CommentDocument implements ActionHandler
{
    public function __construct(private Documents $documents) {}

    public function execute(PendingAction $action): array
    {
        $commentId = $this->documents->addComment(
            $action->user,
            $action->payload['ref'],
            $action->payload['content'],
            $action->payload['quote'] ?? null,
        );

        return ['comment_id' => $commentId, 'url' => $action->payload['url'] ?? null];
    }
}
