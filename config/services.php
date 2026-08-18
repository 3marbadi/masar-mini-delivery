<?php

return [

    'masar' => [
        'base_url' => env('MASAR_INTEGRATION_URL'),
        'client_id' => env('MASAR_INTEGRATION_CLIENT_ID'),
        'client_secret' => env('MASAR_INTEGRATION_CLIENT_SECRET'),
        'token_path' => '/api/v1/integration/auth/token',
        'endpoint_path' => '/api/v1/integration/events',
        'token_safety_seconds' => (int) env('MASAR_INTEGRATION_TOKEN_SAFETY_SECONDS', 60),
        'timeout' => 10,
        'batch_limit' => 50,
        'max_attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

];
