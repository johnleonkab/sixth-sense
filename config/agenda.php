<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Working hours
    |--------------------------------------------------------------------------
    |
    | The scheduler only looks for slots within these hours, on these days
    | (1 = Monday … 7 = Sunday), in the person's timezone, leaving lunch free.
    |
    */

    'timezone' => env('AGENDA_TIMEZONE', 'Europe/Madrid'),

    'working_days' => [1, 2, 3, 4, 5],

    'day_starts' => env('AGENDA_DAY_STARTS', '09:00'),

    'day_ends' => env('AGENDA_DAY_ENDS', '18:30'),

    'lunch' => ['starts' => env('AGENDA_LUNCH_STARTS', '14:00'), 'ends' => env('AGENDA_LUNCH_ENDS', '15:00')],

    /*
    |--------------------------------------------------------------------------
    | Slots
    |--------------------------------------------------------------------------
    |
    | Slots start on quarter hours, never sooner than the lead time from now,
    | and keep a short gap with the meetings around them.
    |
    */

    'granularity_minutes' => 15,

    'lead_minutes' => 30,

    'buffer_minutes' => 10,

    /*
    |--------------------------------------------------------------------------
    | Task assessor
    |--------------------------------------------------------------------------
    |
    | Decides urgency, length and kind of each task.
    | Supported: "llm", "fake". "gemini" is an alias of "llm".
    |
    */

    'assessor' => env('AGENDA_ASSESSOR', 'llm'),

];
