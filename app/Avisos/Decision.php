<?php

namespace App\Avisos;

/**
 * Whether a candidate is an aviso, and the reason stored on the item.
 */
final readonly class Decision
{
    public function __construct(
        public bool $relevant,
        public string $why,
        public int $score,
        public bool $doubtful = false,
    ) {}

    public static function skip(bool $doubtful = false): self
    {
        return new self(false, '', 0, $doubtful);
    }

    public static function surface(string $why, int $score): self
    {
        return new self(true, $why, $score, false);
    }
}
