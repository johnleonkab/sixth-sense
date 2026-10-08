<?php

namespace App\Actions;

use App\Models\PendingAction;

/**
 * Runs one type of confirmed action against the external service.
 */
interface ActionHandler
{
    /**
     * @return array<string, mixed> Result to show on screen.
     *
     * @throws \Throwable when the action could not be done; the message is shown to the person.
     */
    public function execute(PendingAction $action): array;
}
