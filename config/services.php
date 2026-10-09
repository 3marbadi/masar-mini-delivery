<?php

return [

    'masar' => [
        'base_url' => env('MASAR_INTEGRATION_URL'),
        'client_id' => env('MASAR_INTEGRATION_CLIENT_ID'),
        'client_secret' => env('MASAR_INTEGRATION_CLIENT_SECRET'),
        'token_path' => '/api/v1/integration/auth/token',
        'endpoint_path' => '/api/v1/integration/events',

        /*
         * The credential-management legs (Masar CONTRACT §13.28.4, D29, v5.16).
         *
         * A base segment rather than two path templates with a placeholder in them:
         * the courier identity is interpolated by MasarCredentialClient, which
         * encodes it, and a template string would invite a `str_replace` at the
         * call site instead.
         *
         * Nothing here names a host. The base url stays the single place a
         * deployment points at Masar, so a staging run cannot reach production by
         * inheriting half a path.
         */
        'representatives_path' => '/api/v1/integration/representatives',
        'token_safety_seconds' => (int) env('MASAR_INTEGRATION_TOKEN_SAFETY_SECONDS', 60),
        'timeout' => 10,
        'batch_limit' => 50,
        'max_attempts' => 5,

        /*
         * Two timeouts for the credential legs, and the shorter one is the read.
         *
         * The read runs while an administrator waits for a representative page, so
         * it should give up quickly and let the section render its unavailable
         * state. The mutation does more work on the far side — a transaction and a
         * bcrypt hash — and timing one out is the expensive ambiguous case
         * (§13.28.11: the result becomes unknown, and only a human can close it),
         * so it is given more room rather than less.
         *
         * Both carry defaults, so a deployment needs no `.env` change to boot.
         */
        'credential_read_timeout' => (int) env('MASAR_CREDENTIAL_READ_TIMEOUT', 5),
        'credential_mutation_timeout' => (int) env('MASAR_CREDENTIAL_TIMEOUT', 10),

        /*
         * Whether outbound events carry the administrative destination and the
         * approved delivery fee (Masar CONTRACT §3.7, v5.19 — D3).
         *
         * Off by default, and that default is the deployment order rather than
         * caution. Masar answers an event it cannot validate with `422`, and
         * §3.21.7 makes every 4xx terminal and never retried — so sending the new
         * fields to a receiver that predates them would not be a degraded sync,
         * it would lose those orders for good. The receiver ships first and
         * accepts both shapes; this is switched on afterwards.
         *
         * It gates both halves of the feature on purpose. While it is off, the
         * destination also cannot be edited on an order Masar has already been
         * told about — an edit applied locally and suppressed on the wire is
         * precisely the silent divergence D2's restriction existed to prevent.
         */
        'destination_sync' => (bool) env('MASAR_DESTINATION_SYNC_ENABLED', false),
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
