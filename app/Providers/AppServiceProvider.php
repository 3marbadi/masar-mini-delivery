<?php

namespace App\Providers;

use App\Models\MasarIntegrationClient;
use App\Models\Representative;
use App\Policies\RepresentativePolicy;
use App\Support\DestructiveDatabaseGuard;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        // Stated rather than left to Laravel's convention-based discovery.
        //
        // Discovery would find App\Policies\RepresentativePolicy on its own, and
        // that is precisely the problem: the one authorization decision this
        // application makes about Masar credentials (§13.28.14) would then be
        // bound by a naming coincidence, and renaming either class would silently
        // open the ability rather than break the build.
        Gate::policy(Representative::class, RepresentativePolicy::class);

        // The target of a destroying command must be stated, never inherited.
        //
        // `migrate:fresh` run without naming a database resolves whatever
        // `config('database.connections.mysql.database')` happens to be — which
        // in this checkout is the end-to-end database, not the test one — and
        // drops every table in it without asking. That happened, and it cost a
        // hundred rows of fixture data.
        //
        // Hooked on `CommandStarting` rather than inside any one command,
        // because the point is to cover the commands nobody thought about,
        // including the ones a future Laravel adds.
        Event::listen(CommandStarting::class, [DestructiveDatabaseGuard::class, 'assertCommandIsSafe']);

        // The stylesheet for the «حساب مسار» card and its one-time credential modal.
        //
        // Registered through Filament's own asset registry rather than built with
        // Tailwind, because the panel's compiled stylesheet carries Filament's
        // semantic `.fi-*` selectors and no utility selectors at all — a view written
        // in utilities renders as unstyled text. `php artisan filament:assets` copies
        // this to `public/css/app/masar-credential.css`, and the `@filamentStyles`
        // directive the panel layout already calls emits the `<link>` for it.
        //
        // The consequence worth stating: this feature adds **no** Node, npm, Vite or
        // custom-theme step to the deployment path. The source file is tracked, and
        // the published copy lives beside the Filament asset this project already
        // commits.
        FilamentAsset::register([
            Css::make('masar-credential', resource_path('css/masar-credential.css')),
            // The copy buttons' Alpine component, registered the same way and for the
            // same reason: a real file that can be read and tested, rather than an
            // inline expression. It also keeps the secret out of any attribute — the
            // component reads the value from the node already displaying it.
            Js::make('masar-credential', resource_path('js/masar-credential.js')),
        ]);

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
