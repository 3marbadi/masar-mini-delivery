<?php

use App\Http\Controllers\Api\V1\Integration\EventController;
use App\Http\Controllers\Api\V1\Integration\HealthController;
use App\Http\Controllers\Api\V1\Integration\TokenController;
use Illuminate\Support\Facades\Route;

/*
 * The receiving half of the Masar integration (CONTRACT §3.21).
 *
 * Mini Delivery's first API surface. Everything else this application does is a
 * Filament panel and an outbox that calls Masar; this is the one place Masar
 * calls here, and it exists for one announcement: what became of an order during
 * a tour.
 *
 * The paths mirror Masar's own integration surface deliberately — the same
 * server-to-server pattern with the parties exchanged — and they collide with
 * nothing, since this application had no API routes at all before now.
 *
 * `masar.request-id` runs on all three, health included, so every answer on this
 * channel is traceable from Masar's side. Authentication is only on the event
 * leg: the token leg is where a token comes from, and health deliberately proves
 * reachability before any credential is involved.
 */
Route::prefix('v1/integration')->middleware('masar.request-id')->group(function (): void {
    Route::post('/auth/token', TokenController::class)->middleware('throttle:masar-integration-token');
    Route::get('/health', HealthController::class)->middleware('throttle:masar-integration-health');
    Route::post('/events', EventController::class)
        ->middleware(['masar.integration.client', 'throttle:masar-integration-events']);
});
