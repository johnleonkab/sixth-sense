<?php

namespace App\Sense;

use RuntimeException;

/**
 * An existing repository is missing a piece of the scaffold, or a type file
 * does not have the shape every type file must have.
 */
class ScaffoldIncomplete extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
