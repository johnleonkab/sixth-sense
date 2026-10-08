<?php

namespace App\Connections;

use App\Models\ConnectedAccount;
use RuntimeException;

class AccountNeedsReconnection extends RuntimeException
{
    public function __construct(public readonly ConnectedAccount $account)
    {
        parent::__construct("Connected account [{$account->id}] needs to be connected again.");
    }
}
