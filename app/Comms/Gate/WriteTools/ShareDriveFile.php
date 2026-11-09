<?php

namespace App\Comms\Gate\WriteTools;

use App\Models\Comms\CommsProposal;

/**
 * Shares one Drive file. A second call returns the id already recorded.
 */
class ShareDriveFile
{
    public function send(CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        return 'drive-share:'.$proposal->key;
    }
}
