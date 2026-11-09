<?php

namespace App\Comms\Context;

use App\Comms\Events\EventType;

/**
 * Who produced a fact. A higher rank is kept when two facts disagree.
 */
enum FactOrigin: string
{
    case User = 'user';
    case Correction = 'correction';
    case Observed = 'observed';
    case Inferred = 'inferred';

    public function rank(): int
    {
        return match ($this) {
            self::User => 4,
            self::Correction => 3,
            self::Observed => 2,
            self::Inferred => 1,
        };
    }

    public static function fromEvent(EventType $type): self
    {
        return match ($type) {
            EventType::ContextCorrected => self::Correction,
            EventType::ContextObserved => self::Observed,
            EventType::ContextInferred => self::Inferred,
            default => self::User,
        };
    }
}
