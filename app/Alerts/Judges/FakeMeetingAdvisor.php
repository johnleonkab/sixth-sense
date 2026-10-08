<?php

namespace App\Alerts\Judges;

use App\Alerts\MeetingAdvisor;
use App\Alerts\MeetingBriefing;
use App\Connectors\Calendar\CalendarEvent;
use App\Models\User;

/**
 * For tests and demo mode: routines need nothing; anything with a client,
 * a review or a linked document needs preparation.
 */
class FakeMeetingAdvisor implements MeetingAdvisor
{
    /** @var list<string> */
    public array $advised = [];

    public function advise(User $user, CalendarEvent $event): MeetingBriefing
    {
        $this->advised[] = $event->title;

        if (preg_match('/revisi[oó]n|contrato|cliente|presentaci[oó]n/iu', $event->title) || $event->documents !== []) {
            return MeetingBriefing::make(true, 20, "Prepara «{$event->title}»: repasa lo que se va a revisar.");
        }

        return MeetingBriefing::make(false, 2, 'Reunión de seguimiento, sin nada que preparar.');
    }
}
