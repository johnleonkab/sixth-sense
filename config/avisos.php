<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook secrets
    |--------------------------------------------------------------------------
    |
    | Each source signs the raw body with HMAC-SHA256. An empty secret rejects
    | every delivery: a webhook is never accepted unsigned.
    |
    */

    'webhooks' => [
        'slack' => env('AVISOS_SLACK_WEBHOOK_SECRET'),
        'gmail' => env('AVISOS_GMAIL_WEBHOOK_SECRET'),
        'jira' => env('AVISOS_JIRA_WEBHOOK_SECRET'),
        'notion' => env('AVISOS_NOTION_WEBHOOK_SECRET'),
    ],

];
