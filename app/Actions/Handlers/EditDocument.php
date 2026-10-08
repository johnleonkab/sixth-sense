<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\EditPlan;
use App\Models\PendingAction;

class EditDocument implements ActionHandler
{
    public function __construct(private Documents $documents) {}

    public function execute(PendingAction $action): array
    {
        $plan = $action->payload['plan'];

        $this->documents->applyEdits($action->user, new EditPlan(
            ref: $plan['ref'],
            title: $plan['title'],
            revisionId: $plan['revision_id'],
            changes: $plan['changes'],
            operations: $plan['operations'],
            url: $plan['url'],
        ));

        return ['url' => $plan['url']];
    }
}
