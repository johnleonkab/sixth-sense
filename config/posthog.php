<?php

return [
    'api_key' => env('POSTHOG_PROJECT_TOKEN'),
    'host' => env('POSTHOG_HOST'),
    'disabled' => (bool) env('POSTHOG_DISABLED', false),
    'ai_privacy_mode' => (bool) env('POSTHOG_AI_PRIVACY_MODE', true),
];
