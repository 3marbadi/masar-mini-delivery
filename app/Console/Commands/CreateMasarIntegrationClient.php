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

        $secret = $this->generateSecret();

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

    /**
     * A client secret in characters the console cannot reinterpret.
     *
     * This used to be `Str::password(40)`, whose alphabet includes `\`, `<` and
     * `>`. The secret's only delivery path is the `client_secret:` line this
     * command prints, and Symfony's output formatter reads all three as markup:
     * it prints `\<` as a bare `<` and swallows any run shaped like `<info>`
     * entirely. The hash stored here is
     * of the value that was generated, so an operator copying the value that was
     * *printed* into Masar's `DELIVERY_SYNC_CLIENT_SECRET` would configure a
     * secret that can never authenticate — and nothing would report it, because
     * from this side nothing is wrong. Masar's own `integration:create-client`
     * carried the same defect and is fixed the same way.
     *
     * Hex has no character the formatter touches, so printed and generated are
     * the same string by construction rather than by luck. 32 random bytes is 256
     * bits — more than the previous alphabet gave at 40 characters — and this is
     * a machine-to-machine secret pasted into configuration, so it needs no
     * human-friendly alphabet and no shortening.
     *
     * `random_bytes` and nothing weaker: it is the CSPRNG, and it throws rather
     * than degrading if the platform cannot supply entropy.
     */
    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
