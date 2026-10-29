<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID', 'DEMO_MAP_ID'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    // CI Activity email-reminder schedule events delivered to n8n (Phase 1: local foundation only).
    // Disabled by default: while `enabled` is false no outbox row is recorded and nothing is sent.
    // The existing database notifications / bell are independent of every setting here.
    'n8n_reminders' => [
        'enabled' => (bool) env('N8N_REMINDERS_ENABLED', false),
        'webhook_url' => env('N8N_REMINDERS_WEBHOOK_URL'),
        'signing_secret' => env('N8N_REMINDERS_SIGNING_SECRET'),
        // Attempt delivery of a transaction's own events right after it commits. Never retries
        // other pending events from a user request; retries run only through the command.
        'dispatch_after_commit' => (bool) env('N8N_REMINDERS_DISPATCH_AFTER_COMMIT', false),
        'timeout_seconds' => (int) env('N8N_REMINDERS_TIMEOUT_SECONDS', 5),
        'max_attempts' => (int) env('N8N_REMINDERS_MAX_ATTEMPTS', 10),
    ],

    // n8n -> Laravel pre-send authorization (Phase 2A-5). NOT USED by the selected Phase 2A-6
    // one-way architecture (Laravel -> n8n only; see docs/n8n/phase-2a-6/README.md): keep it
    // disabled, which makes the route answer 404. Retained, not removed, for a possible future
    // hosting topology that permits inbound machine-to-machine requests.
    'n8n_reminder_authorization' => [
        'enabled' => (bool) env('N8N_REMINDER_AUTHORIZATION_ENABLED', false),
        'token' => env('N8N_REMINDER_AUTHORIZATION_TOKEN'),
    ],

];
