<?php

namespace App\Comms\Linking;

enum AgreementStatus: string
{
    /** Every run returned it with the same fields. Goes on to checks and the critic. */
    case Agreed = 'agreed';

    /** Every run opened it, but disagreed on kind, responders or deadline. Opens with those fields unclear. */
    case UnclearFields = 'unclear_fields';

    /** The runs disagree on whether there is an ask at all. Shown as a possible ask; no playbook. */
    case PossibleAsk = 'possible_ask';

    /** A non-open operation not returned by every run. Applied as uncertain; never runs a playbook. */
    case Uncertain = 'uncertain';
}
