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
    | Supported: "gemini", "fake"
    |
    */

    'judge' => env('ALERTS_JUDGE', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Reply engine
    |--------------------------------------------------------------------------
    |
    | Proposes how to answer each alert (to whom, saying what, what else to
    | do). It is its own piece, so it can become another agent later.
    |
    | Supported: "gemini", "fake"
    |
    */

    'planner' => env('ALERTS_PLANNER', 'gemini'),

    'judges' => [

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            // The reply engine thinks each proposal through: few calls, a capable model.
            'model' => env('ALERTS_GEMINI_MODEL', 'gemini-3.8-flash'),
            // The judge and meeting advice run on every new message: many calls, a light model with a bigger quota.
            'judge_model' => env('ALERTS_GEMINI_JUDGE_MODEL', 'gemini-3.5-flash-lite'),
            // Used when the model is out of quota or overloaded.
            'fallback_model' => env('ALERTS_GEMINI_FALLBACK_MODEL', 'gemini-flash-lite-latest'),
            'thinking_level' => env('ALERTS_GEMINI_THINKING_LEVEL', 'low'),
            'timeout' => (int) env('ALERTS_GEMINI_TIMEOUT', 30),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Watching
    |--------------------------------------------------------------------------
    |
    | New emails are checked every minute. A burst bigger than this is cut
    | to the newest ones, so a backlog never floods the person with alerts.
    |
    */

    'max_new_messages_per_check' => (int) env('ALERTS_MAX_NEW_MESSAGES', 20),

];
