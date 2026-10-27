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
    | How far past the working day a slot may sit when nothing fits inside it,
    | and the earliest and latest that stretch may reach. A person's own task
    | can be shortened, but never below the minimum.
    */

    'stretch_minutes' => (int) env('AGENDA_STRETCH_MINUTES', 45),

    'stretch_earliest' => env('AGENDA_STRETCH_EARLIEST', '08:00'),

    'stretch_latest' => env('AGENDA_STRETCH_LATEST', '20:00'),

    'task_minimum_minutes' => 15,

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
