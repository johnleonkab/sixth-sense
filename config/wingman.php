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

    // The living notes of a call.
    'notes' => [
        // gemini or fake (tests).
        'taker' => env('WINGMAN_NOTES', 'gemini'),
        // New lines are taken into the notes this often while people talk.
        'every_seconds' => (int) env('WINGMAN_NOTES_EVERY_SECONDS', 20),
        // Lines per batch at most.
        'max_lines' => 80,
        'model' => env('WINGMAN_NOTES_MODEL', 'gemini-3.8-flash'),
    ],

    // The agent that brings in what helps during a call.
    'agent' => [
        // gemini or fake (tests).
        'detector' => env('WINGMAN_AGENT', 'gemini'),
        'model' => env('WINGMAN_AGENT_MODEL', 'gemini-3.8-flash'),
        // New lines are read this often while people talk.
        'every_seconds' => (int) env('WINGMAN_AGENT_EVERY_SECONDS', 12),
        'max_lines' => 40,
        // Only findings this sure of are checked.
        'min_confidence' => 0.6,
        // At most this many cards per reading; something not urgent waits this long after the last one.
        'max_per_reading' => 2,
        'quiet_seconds' => (int) env('WINGMAN_AGENT_QUIET_SECONDS', 90),
    ],

    // What is left to do, at the end of a call.
    'follow_ups' => [
        // gemini or fake (tests).
        'planner' => env('WINGMAN_FOLLOW_UPS', 'gemini'),
        'model' => env('WINGMAN_FOLLOW_UPS_MODEL', 'gemini-3.8-flash'),
    ],

];
