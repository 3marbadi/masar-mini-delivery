<?php

namespace Tests\Feature;

use App\Models\MasarIntegrationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The secret `masar:create-integration-client` prints has to be the secret it
 * hashed.
 *
 * It was not, and the failure was silent. The command used `Str::password(40)`,
 * whose alphabet includes `\`, `<` and `>`, and its only delivery path is
 * Symfony's console formatter, which treats all three as markup — `\<` prints as
 * a bare `<`, and a run shaped like `<info>` disappears. The hash stored is of
 * the generated value, so the operator who follows the command's own instruction
 * and copies the printed value into Masar's `DELIVERY_SYNC_CLIENT_SECRET`
 * configures a secret that can never authenticate. Nothing reports it: the row is
 * valid, the client is active, and Masar's outbound sync simply fails to get a
 * token forever.
 *
 * So these tests pin the invariant end to end — generated, printed, and
 * authenticating are one string — rather than testing a generator in isolation,
 * because the formatter is the part that was wrong.
 */
class MasarIntegrationClientProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** 32 random bytes as hex. */
    private const SECRET_FORMAT = '/^[0-9a-f]{64}$/';

    public function test_the_printed_secret_is_the_secret_that_was_hashed(): void
    {
        $secret = $this->createClient('Masar', 'masar');

        $client = MasarIntegrationClient::query()->sole();

        $this->assertMatchesRegularExpression(self::SECRET_FORMAT, $secret);
        $this->assertSame(64, strlen($secret));
        $this->assertTrue(Hash::check($secret, $client->client_secret_hash));

        // The hash is not the secret. Stated separately because a "hashing" step
        // that became a pass-through would still satisfy the line above.
        $this->assertNotSame($secret, $client->client_secret_hash);
        $this->assertSame('masar', $client->client_id);
        $this->assertTrue($client->isActive());
    }

    public function test_the_printed_secret_authenticates_through_the_real_token_endpoint(): void
    {
        $secret = $this->createClient('Masar', 'masar');

        // The proof that matters: the value the operator would paste into Masar's
        // configuration is the value this boundary accepts. Nothing here reads
        // the hash or the model.
        $this->postJson('/api/v1/integration/auth/token', [
            'client_id' => 'masar',
            'client_secret' => $secret,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token', 'expires_in']);
    }

    public function test_a_near_miss_secret_is_still_refused(): void
    {
        $secret = $this->createClient('Masar', 'masar');

        // One character different, so the success above cannot be a pass-through.
        $this->postJson('/api/v1/integration/auth/token', [
            'client_id' => 'masar',
            'client_secret' => substr($secret, 0, -1).(str_ends_with($secret, 'a') ? 'b' : 'a'),
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_FAILED');
    }

    public function test_no_column_of_the_stored_row_holds_the_plaintext(): void
    {
        $secret = $this->createClient('Masar', 'masar');

        // Read raw rather than through the model, so a column the model happens
        // not to expose is still checked.
        $row = (array) DB::table('masar_integration_clients')->sole();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                $secret,
                (string) $value,
                "the plaintext secret reached masar_integration_clients.{$column}",
            );
        }
    }

    public function test_two_clients_receive_different_secrets(): void
    {
        $first = $this->createClient('Masar', 'masar');
        $second = $this->createClient('Masar Staging', 'masar_staging');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression(self::SECRET_FORMAT, $second);
    }

    public function test_a_duplicate_client_id_is_refused_and_nothing_is_written(): void
    {
        $this->createClient('Masar', 'masar');

        $this->artisan('masar:create-integration-client', [
            'name' => 'Masar Again',
            '--client-id' => 'masar',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('masar_integration_clients', 1);
    }

    /**
     * Why the alphabet is constrained, written down so the rule is not undone.
     *
     * Hex is not an arbitrary preference: it is the property that makes printing
     * safe. This drives the real formatter over the characters the old generator
     * could emit, shows that each is rewritten, and then shows the current format
     * passing through untouched.
     */
    public function test_console_unsafe_secret_alphabets_are_corrupted_by_the_formatter(): void
    {
        // Every one of these is a value `Str::password()` could have produced.
        foreach (['AB\\<CD', 'AB\\>CD', 'AB<info>CD', 'x<comment>y'] as $unsafe) {
            $this->assertNotSame(
                $unsafe,
                $this->throughFormatter($unsafe),
                "[{$unsafe}] survived the formatter, so this test no longer documents anything",
            );
        }

        // The format in use does survive, for every byte value hex can hold.
        foreach (['0123456789abcdef', str_repeat('f', 64), bin2hex(random_bytes(32))] as $safe) {
            $this->assertSame($safe, $this->throughFormatter($safe));
        }
    }

    /** Run a string through the same formatter the command's output passes through. */
    private function throughFormatter(string $value): string
    {
        $output = new BufferedOutput;
        $output->setFormatter(new OutputFormatter(true));
        $output->writeln($value);

        return preg_replace('/\e\[[0-9;]*m/', '', rtrim($output->fetch(), "\r\n"));
    }

    /** Run the real command and lift the secret back out of its real output. */
    private function createClient(string $name, string $clientId): string
    {
        $this->assertSame(0, Artisan::call('masar:create-integration-client', [
            'name' => $name,
            '--client-id' => $clientId,
        ]));

        $output = Artisan::output();

        // Not anchored with `$`: console output is CRLF on this platform, and the
        // carriage return would sit between the captured value and the line end.
        $this->assertSame(1, preg_match('/^client_secret:\s*(\S+)/m', $output, $matches), 'no secret line in the output');
        $this->assertStringContainsString('will not be shown again', $output);

        return $matches[1];
    }
}
