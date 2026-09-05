<?php

namespace App\Console\Commands;

use App\Models\MasarIntegrationClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the credential Masar uses to reach this system (CONTRACT §3.21.2).
 *
 * The counterpart of Masar's own `integration:create-client`, and the only way
 * a credential enters this application: never a seeder with a fixed secret,
 * never a constant, never a value committed to the repository. The secret is
 * generated here, hashed, and printed once — it is not stored in plaintext and
 * cannot be recovered, only replaced.
 *
 * The operator copies it into Masar's `DELIVERY_SYNC_CLIENT_SECRET`.
 */
class CreateMasarIntegrationClient extends Command
{
    protected $signature = 'masar:create-integration-client {name=Masar} {--client-id=}';

    protected $description = 'Create the integration client Masar uses to announce order statuses, and show its secret once.';

    public function handle(): int
    {
        $clientId = $this->option('client-id') ?: Str::slug($this->argument('name'), '_');

        if (MasarIntegrationClient::query()->where('client_id', $clientId)->exists()) {
            $this->error('The client_id already exists.');

            return self::FAILURE;
        }

        $secret = Str::password(40);

        MasarIntegrationClient::create([
            'name' => $this->argument('name'),
            'client_id' => $clientId,
            'client_secret_hash' => Hash::make($secret),
            'status' => 'active',
        ]);

        $this->info('Masar integration client created. Store this secret now; it will not be shown again.');
        $this->line('client_id: '.$clientId);
        $this->line('client_secret: '.$secret);

        return self::SUCCESS;
    }
}
