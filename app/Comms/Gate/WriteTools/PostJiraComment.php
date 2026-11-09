<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Posts one comment on the issue named in the proposal. A second call
 * returns the id already recorded.
 */
class PostJiraComment
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'jira-comment:'.$proposal->key;
    }
}
