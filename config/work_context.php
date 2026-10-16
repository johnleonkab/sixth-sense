<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Summarizer
    |--------------------------------------------------------------------------
    |
    | Reads the person's recent activity and writes what they are working on,
    | why and with whom. It uses the capable model of the text provider.
    |
    | Supported: "llm", "fake". "gemini" is an alias of "llm".
    |
    */

    'summarizer' => env('WORK_CONTEXT_SUMMARIZER', 'llm'),

    // A whole week of activity is a long read: more time than the alerts get.
    'timeout' => (int) env('WORK_CONTEXT_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Built every night, in the app's timezone, for every user who switched
    | it on. Read only: it never changes anything in the services.
    |
    */

    'run_at' => env('WORK_CONTEXT_RUN_AT', '04:30'),

    /*
    |--------------------------------------------------------------------------
    | What is read
    |--------------------------------------------------------------------------
    |
    | How many days back each source is read, and at most how much of it.
    | Documents and pages also get an excerpt of the most recent ones.
    |
    */

    'days' => [
        'slack' => 5,
        'gmail' => 7,
        'documents' => 7,
    ],

    'limits' => [
        'slack' => 150,
        'gmail_inbox' => 60,
        'gmail_sent' => 40,
        'documents' => 25,
        'pages' => 25,
        'excerpts' => 5,
        'excerpt_length' => 800,
    ],

];
