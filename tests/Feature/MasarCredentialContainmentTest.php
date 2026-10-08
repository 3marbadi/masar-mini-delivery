<?php

namespace Tests\Feature;

use App\Enums\MasarCredentialFailure;
use App\Exceptions\MasarCredentialException;
use App\Models\Representative;
use App\Services\Integration\MasarCredentialClient;
use App\Services\Integration\MasarIssuedCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Where the one-time password is allowed to be, and where it is not
 * (Masar CONTRACT §13.28.10, §13.28.18, D29).
 *
 * §13.28.10 lists the places the plaintext must never reach — a column, a log, a
 * cache, a session, an outbound row, a URL, a browser store — and says it lives in
 * «جسمُ جواب مَسار، والطلبُ الواحدُ عند الشركة الذي يُعالِج ذلك الجواب» and nowhere
 * else. These tests are that list, read back as assertions.
 *
 * The marker below is deliberately distinctive. A sweep that looked for a
 * realistic-looking password would pass over a truncated or re-encoded copy of it;
 * a long unmistakable string is found wherever any part of it lands.
 */
class MasarCredentialContainmentTest extends TestCase
{
    use RefreshDatabase;

    private const UID = '0199b2c4-8e1a-7f3d-9c2e-5a7b1d3f6e80';

    private const CREDENTIAL_URL = 'https://masar.test/api/v1/integration/representatives/'.self::UID.'/credential';

    private const TOKEN_URL = 'https://masar.test/api/v1/integration/auth/token';

    /** The value that must not survive the request. */
    private const MARKER = 'DO-NOT-PERSIST-TEST-7XKQWRZ2';

    private const SECRET = 'client-secret-must-not-be-logged';

    private const TOKEN = 'bearer-token-must-not-be-logged';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set([
            'services.masar.base_url' => 'https://masar.test',
            'services.masar.client_id' => 'mini-delivery',
            'services.masar.client_secret' => self::SECRET,
            'services.masar.token_path' => '/api/v1/integration/auth/token',
            'services.masar.representatives_path' => '/api/v1/integration/representatives',
            'services.masar.token_safety_seconds' => 60,
        ]);
    }

    // -------------------------------------------------------------- the sweep

    public function test_an_issued_password_reaches_no_database_table(): void
    {
        $this->fakeApplied();

        $representative = $this->representative();
        $client = app(MasarCredentialClient::class);

        $created = $client->create($representative, 'omar.badi', (string) Str::uuid());
        $rotated = $client->rotate($representative, (string) Str::uuid());
        $client->status($representative);

        $this->assertSame(self::MARKER, $created->password);
        $this->assertSame(self::MARKER, $rotated->password);

        // Every table in the schema, not a chosen few: the question is whether the
        // plaintext reached *any* column, and naming the tables I expect it to be
        // absent from would prove only that my expectations are consistent.
        $leaks = [];

        foreach ($this->tables() as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach ((array) $row as $column => $value) {
                    if (is_string($value) && str_contains($value, self::MARKER)) {
                        $leaks[] = "{$table}.{$column}";
                    }
                }
            }
        }

        $this->assertSame([], $leaks, 'the one-time password reached: '.implode(', ', $leaks));
    }

    public function test_an_issued_password_reaches_no_outbox_row_and_no_integration_ledger(): void
    {
        $this->fakeApplied();

        $representative = $this->representative();
        $client = app(MasarCredentialClient::class);

        $client->create($representative, 'omar.badi', (string) Str::uuid());
        $client->rotate($representative, (string) Str::uuid());

        // Credential management is synchronous and is not an order event
        // (§13.28.19). The outbox persists its payload as JSON, so a credential
        // riding it would be a password written to MySQL.
        $this->assertDatabaseCount('integration_outbox', 0);
        $this->assertDatabaseCount('masar_integration_events', 0);
        $this->assertDatabaseCount('order_integration_states', 0);
    }

    public function test_an_issued_password_reaches_no_cache_or_session_store(): void
    {
        $this->fakeApplied();

        app(MasarCredentialClient::class)->create($this->representative(), 'omar.badi', (string) Str::uuid());

        // The cache holds the integration bearer token and must hold nothing else
        // from this workflow. Walked rather than asked by key, since the point is
        // that no key carries it.
        $cached = Cache::getStore();
        $contents = method_exists($cached, 'all') ? $cached->all() : [];

        foreach ($contents as $key => $value) {
            $this->assertStringNotContainsString(self::MARKER, (string) (is_scalar($value) ? $value : json_encode($value)),
                "the one-time password reached the cache under [{$key}]");
        }

        $this->assertStringNotContainsString(self::MARKER, json_encode(session()->all(), JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    /**
     * The DTO refuses to be serialized, which is how an accidental persist fails
     * loudly rather than silently writing a secret.
     *
     * This application is configured for database-backed cache and sessions in
     * production, so `Cache::put($credential)` or `session()->put($credential)`
     * would otherwise put a password in MySQL.
     */
    public function test_the_issued_credential_cannot_be_serialized(): void
    {
        $this->fakeApplied();

        $issued = app(MasarCredentialClient::class)->create($this->representative(), 'omar.badi', (string) Str::uuid());

        $this->assertInstanceOf(MasarIssuedCredential::class, $issued);

        // The database store, not the array store the test environment defaults to:
        // the array store keeps the object in memory and never serializes it, so it
        // could not exercise the guard. `cache.default = database` is what this
        // application's own `.env.example` configures, so this is the production
        // shape of the mistake.
        config(['cache.default' => 'database']);

        foreach ([
            'serialize' => fn () => serialize($issued),
            'cache' => fn () => Cache::put('leak', $issued, 60),
        ] as $route => $attempt) {
            try {
                $attempt();
                $this->fail("a credential was serialized through [{$route}]");
            } catch (LogicException $refusal) {
                // And the refusal itself names no value.
                $this->assertStringNotContainsString(self::MARKER, $refusal->getMessage());
            }
        }

        // Nothing was written on the way to being refused.
        $this->assertSame(0, DB::table('cache')->count());
    }

    /**
     * JSON encoding redacts rather than refusing.
     *
     * A readonly class with public properties encodes by default, so this is the
     * path a log handler or a JSON column write would take — and the one place
     * where throwing would be worse than redacting, since a diagnostic that raises
     * turns a careless log line into an outage.
     */
    public function test_json_encoding_the_issued_credential_redacts_the_password(): void
    {
        $this->fakeApplied();

        $issued = app(MasarCredentialClient::class)->create($this->representative(), 'omar.badi', (string) Str::uuid());

        $encoded = json_encode($issued, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::MARKER, $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }

    public function test_dumping_the_issued_credential_redacts_the_password(): void
    {
        $this->fakeApplied();

        $issued = app(MasarCredentialClient::class)->create($this->representative(), 'omar.badi', (string) Str::uuid());

        $dumped = print_r($issued->__debugInfo(), true);

        $this->assertStringNotContainsString(self::MARKER, $dumped);
        $this->assertStringContainsString('[redacted]', $dumped);
        $this->assertStringContainsString('omar.badi', $dumped);
    }

    // ---------------------------------------------------------------- the log

    public function test_a_successful_operation_logs_the_safe_fields_and_no_secret(): void
    {
        $this->fakeApplied();

        $records = $this->captureLog();
        $representative = $this->representative();
        $mutation = (string) Str::uuid();

        app(MasarCredentialClient::class)->create($representative, 'omar.badi', $mutation, actorId: 42);

        $created = $this->record($records, 'masar.credential.created');

        // What §13.28.18 permits: the actor, the courier, the identity, the name,
        // the mutation id, Masar's request id and the outcome.
        $this->assertSame(42, $created['context']['admin_user_id']);
        $this->assertSame($representative->getKey(), $created['context']['representative_id']);
        $this->assertSame(self::UID, $created['context']['integration_uid']);
        $this->assertSame('omar.badi', $created['context']['login_name']);
        $this->assertSame($mutation, $created['context']['client_mutation_id']);
        $this->assertSame('req-mutation', $created['context']['masar_request_id']);
        $this->assertSame('applied', $created['context']['outcome']);

        $this->assertNoSecretsIn($records);
    }

    public function test_a_replay_is_logged_as_already_applied(): void
    {
        $this->fakeReplayed();

        $records = $this->captureLog();

        app(MasarCredentialClient::class)->rotate($this->representative(), (string) Str::uuid(), actorId: 7);

        // The distinction §13.28.11 draws: no new password was issued, and the
        // record says so rather than reading like a reset that just happened.
        $this->assertSame('already_applied', $this->record($records, 'masar.credential.rotated')['context']['outcome']);
        $this->assertNoSecretsIn($records);
    }

    public function test_a_status_read_is_logged_without_a_credential_value(): void
    {
        $this->fakeApplied();

        $records = $this->captureLog();

        app(MasarCredentialClient::class)->status($this->representative(), actorId: 11);

        $read = $this->record($records, 'masar.credential.status_read');

        $this->assertSame(11, $read['context']['admin_user_id']);
        $this->assertSame('active', $read['context']['credential_status']);
        $this->assertArrayNotHasKey('password', $read['context']);
        $this->assertNoSecretsIn($records);
    }

    public function test_a_refusal_is_logged_as_settled_with_its_machine_codes(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => Http::response([
                'success' => false,
                'error' => ['code' => 'CREDENTIAL_ALREADY_EXISTS', 'message' => 'The courier already has an active credential.'],
                'request_id' => 'req-409',
            ], 409),
        ]);

        $records = $this->captureLog();

        $this->expectFailure(
            fn () => app(MasarCredentialClient::class)->create($this->representative(), 'omar.badi', (string) Str::uuid(), actorId: 3),
            MasarCredentialFailure::CredentialAlreadyExists,
        );

        $failed = $this->record($records, 'masar.credential.failed');

        $this->assertSame('warning', $failed['level']);
        $this->assertSame('credential_already_exists', $failed['context']['failure']);
        $this->assertSame('CREDENTIAL_ALREADY_EXISTS', $failed['context']['error_code']);
        $this->assertSame(409, $failed['context']['http_status']);
        $this->assertSame('req-409', $failed['context']['masar_request_id']);
        $this->assertTrue($failed['context']['settled']);

        $this->assertNoSecretsIn($records);
    }

    /**
     * An unknown result is logged at error, because it leaves a question about
     * production state that somebody has to close (§13.28.11).
     */
    public function test_an_unknown_result_is_logged_at_error_and_marked_unsettled(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        $records = $this->captureLog();

        $this->expectFailure(
            fn () => app(MasarCredentialClient::class)->rotate($this->representative(), (string) Str::uuid(), actorId: 5),
            MasarCredentialFailure::ResultUnknown,
        );

        $failed = $this->record($records, 'masar.credential.failed');

        $this->assertSame('error', $failed['level']);
        $this->assertSame('result_unknown', $failed['context']['failure']);
        $this->assertFalse($failed['context']['settled']);

        $this->assertNoSecretsIn($records);
    }

    public function test_no_response_body_or_header_reaches_the_log(): void
    {
        $this->fakeApplied();

        $records = $this->captureLog();
        $client = app(MasarCredentialClient::class);
        $representative = $this->representative();

        $client->create($representative, 'omar.badi', (string) Str::uuid(), actorId: 1);
        $client->status($representative, actorId: 1);

        $log = $this->flatten($records);

        // The response envelope itself, not only the password inside it: a log line
        // carrying the whole body would carry the password with it next time.
        foreach ([self::MARKER, self::SECRET, self::TOKEN, 'Authorization', 'Bearer ', '"credential":', '"success":'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $log, "[{$forbidden}] reached the application log");
        }
    }

    // --------------------------------------------------------------- helpers

    /** @return list<string> */
    private function tables(): array
    {
        return collect(DB::select('SHOW TABLES'))
            ->map(fn (object $row): string => (string) array_values((array) $row)[0])
            ->reject(fn (string $table): bool => $table === 'migrations')
            ->values()
            ->all();
    }

    private function fakeApplied(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('rotated', self::MARKER)),
            '*/credential' => fn ($request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response($this->mutationBody('created', self::MARKER)),
        ]);
    }

    private function fakeReplayed(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('already_applied')),
            '*/credential' => Http::response($this->mutationBody('already_applied')),
        ]);
    }

    /** @return array<string, mixed> */
    private function tokenBody(): array
    {
        return ['success' => true, 'access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 3600];
    }

    /** @return array<string, mixed> */
    private function statusBody(): array
    {
        return [
            'success' => true,
            'external_courier_id' => self::UID,
            'mapped' => true,
            'has_credential' => true,
            'login_name' => 'omar.badi',
            'credential_status' => 'active',
            'credentials_updated_at' => '2026-10-07T09:00:00Z',
            'request_id' => 'req-status',
        ];
    }

    /** @return array<string, mixed> */
    private function mutationBody(string $status, ?string $password = null): array
    {
        $credential = [
            'login_name' => 'omar.badi',
            'credential_status' => 'active',
            'credentials_updated_at' => '2026-10-07T09:00:00Z',
        ];

        if ($password !== null) {
            $credential['password'] = $password;
        }

        return [
            'success' => true,
            'status' => $status,
            'external_courier_id' => self::UID,
            'mapped' => true,
            'has_credential' => true,
            'credential' => $credential,
            'request_id' => 'req-mutation',
        ];
    }

    private function representative(): Representative
    {
        $representative = Representative::query()->create([
            'name' => 'Omar Badi',
            'phone' => '0911000000',
            'is_active' => true,
        ]);

        $representative->forceFill(['integration_uid' => self::UID])->save();

        return $representative->refresh();
    }

    /**
     * Every record the workflow produces, whatever produced it.
     *
     * Listening rather than adding a log line to make this testable: a test that
     * only inspected logging it had asked for would prove nothing about the logging
     * it had not.
     *
     * An ArrayObject and not an array, because the listener fires after this
     * returns and a returned array would be a snapshot of the empty one.
     *
     * @return \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    private function captureLog(): \ArrayObject
    {
        $records = new \ArrayObject;

        Log::listen(function ($message) use ($records): void {
            $records[] = ['level' => $message->level, 'message' => $message->message, 'context' => $message->context];
        });

        return $records;
    }

    /**
     * @param  \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>  $records
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function record(\ArrayObject $records, string $message): array
    {
        foreach ($records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        $this->fail("no [{$message}] record was written; saw: "
            .implode(', ', array_column($records->getArrayCopy(), 'message')));
    }

    /** @param  \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>  $records */
    private function assertNoSecretsIn(\ArrayObject $records): void
    {
        $log = $this->flatten($records);

        foreach ([self::MARKER, self::SECRET, self::TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }

        // Hash-shaped values have no business here either: Masar never sends one,
        // and a log that started carrying them would be carrying a credential's
        // shadow.
        $this->assertStringNotContainsString('$2y$', $log);
        $this->assertStringNotContainsString('password_hash', $log);
    }

    /** @param  \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>  $records */
    private function flatten(\ArrayObject $records): string
    {
        return collect($records->getArrayCopy())
            ->map(fn (array $record): string => $record['level'].'|'.$record['message'].'|'
                .json_encode($record['context'], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->implode("\n");
    }

    private function expectFailure(callable $call, MasarCredentialFailure $expected): void
    {
        try {
            $call();
        } catch (MasarCredentialException $failure) {
            $this->assertSame($expected, $failure->failure);

            return;
        }

        $this->fail('the credential operation was expected to fail and did not');
    }
}
