<?php

namespace App\Actions;

enum ActionStatus: string
{
    /** Proposed, waiting for the person's "sí". */
    case Pending = 'pending';

    /** Confirmed: it runs when the undo window closes. */
    case Confirmed = 'confirmed';

    case Cancelled = 'cancelled';

    /** Confirmed and then undone within the window: it never ran. */
    case Undone = 'undone';

    /** Claimed by the job that is running it. */
    case Executing = 'executing';

    case Executed = 'executed';

    case Failed = 'failed';

    case Expired = 'expired';
}
