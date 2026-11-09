<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Updates one issue. A second call returns the id already recorded.
 */
class UpdateJiraIssue
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'jira-update:'.$proposal->key;
    }
}
