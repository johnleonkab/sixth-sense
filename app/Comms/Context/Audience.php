<?php

namespace App\Comms\Context;

/**
 * Who is asking. A fact with an empty audience list is readable by all of them.
 */
enum Audience: string
{
    case Voice = 'voice';
    case Importance = 'importance';
    case Triage = 'triage';
    case Classifier = 'classifier';
    case Match = 'match';
    case Reply = 'reply';
    case Scheduler = 'scheduler';
    case Export = 'export';

    public static function fromLayer(?string $layer): self
    {
        return match ($layer) {
            'judge' => self::Importance,
            'triage' => self::Triage,
            'classifier' => self::Classifier,
            'scheduler' => self::Scheduler,
            'reply' => self::Reply,
            default => self::Voice,
        };
    }

    public function lessonLayer(): ?string
    {
        return match ($this) {
            self::Importance => 'judge',
            self::Triage => 'triage',
            self::Classifier => 'classifier',
            self::Scheduler => 'scheduler',
            self::Reply => 'reply',
            default => null,
        };
    }
}
