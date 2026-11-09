<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Creates one Notion page. A second call returns the id already recorded.
 */
class CreateNotionPage
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'notion-page:'.$proposal->key;
    }
}
