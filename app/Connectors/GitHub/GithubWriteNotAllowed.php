<?php

namespace App\Connectors\GitHub;

use App\Models\GithubRepository;
use RuntimeException;

/**
 * The person did not allow code updates on this repository, or GitHub does
 * not let this account push to it.
 */
class GithubWriteNotAllowed extends RuntimeException
{
    public function __construct(GithubRepository $repository)
    {
        parent::__construct("Code updates are not allowed on {$repository->full_name}.");
    }
}
