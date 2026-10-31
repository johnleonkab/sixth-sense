<?php

namespace App\Alerts\Actions;

use App\Alerts\Replies\AlertBrief;
use App\Models\User;

/**
 * Decides what a message that matters asks of the person: a call, a task, a
 * question to answer or just a reply — one or several — and how sure it is.
 */
interface ActionClassifier
{
    /**
     * @return list<ClassifiedAction>
     */
    public function classify(User $user, AlertBrief $brief): array;
}
