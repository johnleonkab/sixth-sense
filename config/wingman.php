<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wingman
    |--------------------------------------------------------------------------
    |
    | Follows the person's Google Meet calls: finds them in the calendar,
    | gathers their context beforehand and, during the call, listens and
    | brings in what helps. See docs/epica-wingman.md.
    |
    */

    // Meetings are prepared this long before they start (and again on opening one).
    'prepare_before_minutes' => (int) env('WINGMAN_PREPARE_BEFORE_MINUTES', 30),

    // What the context gathers, at most.
    'context' => [
        'issues' => 5,
        'pages' => 3,
        'emails' => 5,
        // Emails with the attendees, this far back.
        'email_days' => 30,
        // Attendees whose emails are looked for (the first ones, not the person).
        'email_attendees' => 4,
    ],

];
