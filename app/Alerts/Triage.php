<?php

namespace App\Alerts;

/**
 * Whether an incoming message matters, as decided after it arrives.
 */
enum Triage: string
{
    /** Just arrived: the AI is looking at it. */
    case Judging = 'judging';

    /** It waits for the person's answer: it rings. */
    case Important = 'important';

    /** Listed, quietly: nothing to answer. */
    case Routine = 'routine';
}
