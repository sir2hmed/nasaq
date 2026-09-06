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

    'ai_orchestrator' => [
        'url' => env('AI_SERVICE_URL', 'http://ai-service:8001'),
        'service_token' => env('AI_SERVICE_TOKEN'),
        'callback_base_url' => env('INTERNAL_CALLBACK_BASE_URL', 'http://laravel:8000/api/internal'),
        'callback_token' => env('INTERNAL_CALLBACK_TOKEN'),
        'timeout_seconds' => (int) env('AI_SERVICE_TIMEOUT_SECONDS', 15),
    ],

];
