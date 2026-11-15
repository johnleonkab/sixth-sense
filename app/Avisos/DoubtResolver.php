<?php

namespace App\Avisos;

use App\Models\User;

/**
 * The second look at a Gmail message the rules cannot settle.
 * The default leaves it out. A later judge can replace this binding.
 */
interface DoubtResolver
{
    public function resolve(User $user, Candidate $candidate, Decision $decision): Decision;
}
