<?php

namespace App\Voice\OpenUi;

/**
 * A reference to another OpenUI Lang statement, written bare.
 */
final readonly class Ref
{
    public function __construct(public string $name) {}
}
