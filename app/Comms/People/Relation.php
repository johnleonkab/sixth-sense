<?php

namespace App\Comms\People;

/**
 * How the user relates to a person. The weight is the phase 2 default.
 */
enum Relation: string
{
    case Self = 'self';
    case Boss = 'boss';
    case Report = 'report';
    case Collaborator = 'collaborator';
    case Stakeholder = 'stakeholder';
    case Client = 'client';
    case Vendor = 'vendor';
    case Other = 'other';
    case Unknown = 'unknown';

    public function weight(): int
    {
        return match ($this) {
            self::Self => 0,
            self::Boss => 40,
            self::Client => 35,
            self::Report, self::Collaborator => 25,
            self::Stakeholder => 20,
            self::Vendor, self::Other => 10,
            self::Unknown => 5,
        };
    }
}
