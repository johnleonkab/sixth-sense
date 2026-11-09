<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Posts one comment on the page named in the proposal. A second call
 * returns the id already recorded.
 */
class PostNotionComment
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'notion-comment:'.$proposal->key;
    }
}
