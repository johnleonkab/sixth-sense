<?php

namespace App\Comms\Gate;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Edited = 'edited';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Undone = 'undone';
    case Failed = 'failed';
    case Expired = 'expired';
}
