<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Edits one page. A second call returns the id already recorded.
 */
class EditNotionPage
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'notion-edit:'.$proposal->key;
    }
}
