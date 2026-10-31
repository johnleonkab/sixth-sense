<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relevance judge
    |--------------------------------------------------------------------------
    |
    | Decides which new emails deserve an alert, after cheap rules have left
    | out the noise (newsletters, promotions, automatic mail…).
    |
    | Supported: "llm", "fake". "gemini" is an alias of "llm".
    | The HTTP provider is AI_TEXT_PROVIDER.
    |
    */

    'judge' => env('ALERTS_JUDGE', 'llm'),

    /*
    |--------------------------------------------------------------------------
    | Reply engine
    |--------------------------------------------------------------------------
    |
    | Proposes how to answer each alert (to whom, saying what, what else to
    | do). It is its own piece, so it can become another agent later.
    |
    | Supported: "llm", "fake". "gemini" is an alias of "llm".
    | The HTTP provider is AI_TEXT_PROVIDER.
    |
    */

    'planner' => env('ALERTS_PLANNER', 'llm'),

    /*
    |--------------------------------------------------------------------------
    | Watching
    |--------------------------------------------------------------------------
    |
    | New emails are checked every minute. A burst bigger than this is cut
    | to the newest ones, so a backlog never floods the person with alerts.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Quiet alerts
    |--------------------------------------------------------------------------
    |
    | Alerts judged below this relevance, with nobody waiting and nothing due
    | in the next two days, do not ring: they go in a digest at these times.
    |
    */

    'quiet_below' => (int) env('ALERTS_QUIET_BELOW', 60),

    'digest_at' => ['09:30', '15:30'],

    'max_new_messages_per_check' => (int) env('ALERTS_MAX_NEW_MESSAGES', 20),

];
