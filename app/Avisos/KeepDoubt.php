<?php

namespace App\Avisos;

use App\Models\User;

/**
 * Doubt stays unresolved: the message is not an aviso.
 */
class KeepDoubt implements DoubtResolver
{
    public function resolve(User $user, Candidate $candidate, Decision $decision): Decision
    {
        return $decision;
    }
}
