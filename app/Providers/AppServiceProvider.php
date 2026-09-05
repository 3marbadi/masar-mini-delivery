<?php

namespace App\Providers;

use App\Models\MasarIntegrationClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ceilings for the Masar receiving channel (CONTRACT §3.21).
        //
        // The token leg is keyed by caller and client id, so one misbehaving
        // deployment cannot exhaust the allowance of another. The event leg is
        // keyed by the authenticated client, since by then there is a principal
        // and an IP is the weaker identity. Health is keyed by address alone —
        // it has no principal to key on.
        RateLimiter::for('masar-integration-token', fn (Request $request) => Limit::perMinute(
            (int) config('integration.rate_limits.token', 10),
        )->by($request->ip().'|'.$request->input('client_id')));

        RateLimiter::for('masar-integration-health', fn (Request $request) => Limit::perMinute(
            (int) config('integration.rate_limits.health', 60),
        )->by($request->ip()));

        RateLimiter::for('masar-integration-events', function (Request $request) {
            $client = $request->attributes->get('masar_integration_client');

            return Limit::perMinute((int) config('integration.rate_limits.events', 120))
                ->by($client instanceof MasarIntegrationClient ? 'masar-client:'.$client->id : $request->ip());
        });
    }
}
