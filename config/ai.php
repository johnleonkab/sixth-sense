<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Text provider
    |--------------------------------------------------------------------------
    |
    | Every model call that is not voice: alerts, reply drafts, the nightly
    | work context, the scheduler and Wingman. Voice stays on its own provider.
    |
    | Supported: "deepseek", "gemini"
    |
    */

    'text' => env('AI_TEXT_PROVIDER', 'deepseek'),

    'providers' => [

        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            // Few calls that have to think a proposal through.
            'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-pro'),
            // The judge and meeting advice run on every new message.
            'light_model' => env('DEEPSEEK_LIGHT_MODEL', 'deepseek-flash'),
            'fallback_model' => env('DEEPSEEK_FALLBACK_MODEL', 'deepseek-flash'),
            'timeout' => (int) env('DEEPSEEK_TIMEOUT', 30),
            'light_max_tokens' => 1024,
            'max_tokens' => 4096,
            // Capable calls only. The light model always disables thinking.
            'thinking' => 'low',
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'model' => env('ALERTS_GEMINI_MODEL', 'gemini-3.8-flash'),
            'light_model' => env('ALERTS_GEMINI_JUDGE_MODEL', 'gemini-3.5-flash-lite'),
            'fallback_model' => env('ALERTS_GEMINI_FALLBACK_MODEL', 'gemini-flash-lite-latest'),
            'thinking_level' => env('ALERTS_GEMINI_THINKING_LEVEL', 'low'),
            'timeout' => (int) env('ALERTS_GEMINI_TIMEOUT', 30),
        ],

    ],

];
