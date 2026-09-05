<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inbound integration from Masar (CONTRACT §3.21)
    |--------------------------------------------------------------------------
    |
    | Mini Delivery already talks *to* Masar through the outbox sender. This
    | block configures the other direction: the server-to-server channel Masar
    | calls to announce what became of an order during its tour.
    |
    | The contract version is the one carried in the event envelope, and it is
    | deliberately independent of the version Mini Delivery sends outward —
    | §3.21 is a separate contract from §3.7, not an extension of it.
    |
    */

    'contract_version' => '1.0',

    // The life of a bearer token issued to Masar, in minutes. Mirrors the
    // hour Masar's own integration endpoint grants the delivery company.
    'token_ttl_minutes' => (int) env('MASAR_INTEGRATION_TOKEN_TTL_MINUTES', 60),

    // Per-minute ceilings. Operational settings; the contract fixes only that
    // a rate limit answers 429 with a Retry-After the sender may respect.
    'rate_limits' => [
        'token' => (int) env('MASAR_INTEGRATION_RATE_LIMIT_TOKEN', 10),
        'events' => (int) env('MASAR_INTEGRATION_RATE_LIMIT_EVENTS', 120),
        'health' => (int) env('MASAR_INTEGRATION_RATE_LIMIT_HEALTH', 60),
    ],
];
