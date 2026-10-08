<?php

namespace Tests\Feature;

use App\Enums\MasarCredentialFailure;
use App\Enums\MasarCredentialState;
use App\Exceptions\MasarCredentialException;
use App\Models\Representative;
use App\Services\Integration\MasarCredentialClient;
use App\Services\Integration\MasarIssuedCredential;
use App\Services\Integration\MasarReplayedCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mini's half of the credential channel (Masar CONTRACT §13.28, D29, v5.16).
 *
 * Everything is faked at the HTTP boundary — no request leaves the test — and most
 * of the weight is on the asymmetry the contract draws between the read and the two
 * mutations. A read may be repeated; a mutation may not, ever, for any reason. The
 * assertions that matter most are therefore the request *counts*, because the
 * failure this layer exists to prevent is a second rotation that invalidates a
 * password the courier already has.
 */
class MasarCredentialClientTest extends TestCase
{
    use RefreshDatabase;

    private const UID = '0199b2c4-8e1a-7f3d-9c2e-5a7b1d3f6e80';

    private const CREDENTIAL_URL = 'https://masar.test/api/v1/integration/representatives/'.self::UID.'/credential';

    private const ROTATION_URL = self::CREDENTIAL_URL.'/rotation';

    private const TOKEN_URL = 'https://masar.test/api/v1/integration/auth/token';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set([
            'services.masar.base_url' => 'https://masar.test',
            'services.masar.client_id' => 'mini-delivery',
            'services.masar.client_secret' => 'client-secret',
            'services.masar.token_path' => '/api/v1/integration/auth/token',
            'services.masar.representatives_path' => '/api/v1/integration/representatives',
            'services.masar.token_safety_seconds' => 60,
        ]);
    }

    // ---------------------------------------------------------------- status

    public function test_an_unmapped_courier_reads_as_a_normal_empty_state(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->statusBody(mapped: false))]);

        $status = $this->client()->status($this->representative());

        // Not an error: every representative passes through this state before Masar
        // has heard of them (§13.28.5).
        $this->assertFalse($status->mapped);
        $this->assertTrue($status->isUnmapped());
        $this->assertFalse($status->hasCredential);
        $this->assertNull($status->loginName);
        $this->assertSame(MasarCredentialState::None, $status->state);
        $this->assertNull($status->credentialsUpdatedAt);
        $this->assertTrue($status->needsFirstCredential());
    }

    public function test_a_mapped_courier_without_a_credential_reads_as_none(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->statusBody())]);

        $status = $this->client()->status($this->representative());

        $this->assertTrue($status->mapped);
        $this->assertFalse($status->hasCredential);
        $this->assertSame(MasarCredentialState::None, $status->state);
        $this->assertTrue($status->needsFirstCredential());
    }

    public function test_an_active_credential_reads_with_its_login_name_and_instant(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ))]);

        $status = $this->client()->status($this->representative());

        $this->assertTrue($status->hasCredential);
        $this->assertSame('omar.badi', $status->loginName);
        $this->assertSame(MasarCredentialState::Active, $status->state);
        $this->assertSame('2026-10-07 09:00:00', $status->credentialsUpdatedAt->toDateTimeString());
    }

    public function test_a_retired_only_history_reads_as_retired_without_a_live_credential(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->statusBody(
            hasCredential: false, loginName: 'omar.badi', state: 'retired', updatedAt: '2026-10-07T09:00:00Z',
        ))]);

        $status = $this->client()->status($this->representative());

        // The name is reported though the courier cannot sign in — it is the name a
        // reset will hand back to them (§13.28.5).
        $this->assertFalse($status->hasCredential);
        $this->assertSame('omar.badi', $status->loginName);
        $this->assertTrue($status->isRetiredOnly());
    }

    public function test_the_read_sends_a_bearer_get_to_the_identity_url(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->statusBody())]);

        $this->client()->status($this->representative());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::CREDENTIAL_URL
            && $request->hasHeader('Authorization', 'Bearer access-token')
            && $request->data() === []);
    }

    public function test_a_rejected_token_is_refreshed_and_the_read_retried_once(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->push($this->tokenBody('stale-token'))
                ->push($this->tokenBody('fresh-token')),
            '*/credential' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'AUTH_FAILED', 'message' => 'x']], 401)
                ->push($this->statusBody(hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z')),
        ]);

        $status = $this->client()->status($this->representative());

        $this->assertSame('omar.badi', $status->loginName);

        // Two token calls and two reads: the retry is permitted here because the
        // leg writes nothing (§13.28.11).
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::CREDENTIAL_URL
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_a_second_rejection_is_a_settled_authentication_failure(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => Http::response(['success' => false, 'error' => ['code' => 'AUTH_FAILED', 'message' => 'x'], 'request_id' => 'req-1'], 401),
        ]);

        $failure = $this->failureFrom(fn () => $this->client()->status($this->representative()));

        $this->assertSame(MasarCredentialFailure::AuthenticationFailure, $failure->failure);
        $this->assertSame(401, $failure->httpStatus);
        $this->assertSame('req-1', $failure->requestId);
        $this->assertTrue($failure->isSettled());

        // Bounded: exactly two reads, not a loop.
        $this->assertSame(2, $this->requestsTo(self::CREDENTIAL_URL));
    }

    public function test_a_connection_failure_on_the_read_is_unavailable(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn () => throw new ConnectionException('timed out'),
        ]);

        $failure = $this->failureFrom(fn () => $this->client()->status($this->representative()));

        // A read writes nothing, so this is plain unavailability and not an open
        // question about production state.
        $this->assertSame(MasarCredentialFailure::Unavailable, $failure->failure);
        $this->assertTrue($failure->isSettled());
    }

    #[DataProvider('readFailures')]
    public function test_a_failed_read_is_classified(int $status, ?string $code, MasarCredentialFailure $expected): void
    {
        $body = $code === null ? ['success' => false] : ['success' => false, 'error' => ['code' => $code, 'message' => 'x']];

        $this->fakeToken(['*/credential' => Http::response($body, $status)]);

        $failure = $this->failureFrom(fn () => $this->client()->status($this->representative()));

        $this->assertSame($expected, $failure->failure);
        $this->assertSame($status, $failure->httpStatus);
    }

    /** @return array<string, array{0: int, 1: string|null, 2: MasarCredentialFailure}> */
    public static function readFailures(): array
    {
        return [
            'forbidden client' => [403, 'FORBIDDEN_CLIENT', MasarCredentialFailure::ForbiddenClient],
            'rate limited' => [429, 'SERVER_ERROR', MasarCredentialFailure::RateLimited],
            'server fault' => [500, 'SERVER_ERROR', MasarCredentialFailure::ServerError],
            'gateway down' => [503, null, MasarCredentialFailure::ServerError],
        ];
    }

    public function test_a_status_response_carrying_a_password_is_a_contract_violation(): void
    {
        $body = $this->statusBody(hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z');
        $body['password'] = 'SHOULDNEVERBEHERE';

        $this->fakeToken(['*/credential' => Http::response($body)]);

        $failure = $this->failureFrom(fn () => $this->client()->status($this->representative()));

        $this->assertSame(MasarCredentialFailure::ContractViolation, $failure->failure);

        // And the offending value is not quoted back into the message, which is a
        // log line (§13.28.10).
        $this->assertStringNotContainsString('SHOULDNEVERBEHERE', $failure->getMessage());
    }

    #[DataProvider('malformedStatusBodies')]
    public function test_a_malformed_status_response_is_refused(array $body): void
    {
        $this->fakeToken(['*/credential' => Http::response($body)]);

        $failure = $this->failureFrom(fn () => $this->client()->status($this->representative()));

        $this->assertSame(MasarCredentialFailure::ContractViolation, $failure->failure);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function malformedStatusBodies(): array
    {
        $sound = [
            'success' => true,
            'external_courier_id' => self::UID,
            'mapped' => true,
            'has_credential' => true,
            'login_name' => 'omar.badi',
            'credential_status' => 'active',
            'credentials_updated_at' => '2026-10-07T09:00:00Z',
        ];

        return [
            'no success flag' => [array_diff_key($sound, ['success' => null])],
            'unknown credential status' => [['credential_status' => 'suspended'] + $sound],
            'mapped is a string' => [['mapped' => 'yes'] + $sound],
            'another courier' => [['external_courier_id' => 'someone-else'] + $sound],
            'active without a login name' => [['login_name' => null] + $sound],
            'active without an instant' => [['credentials_updated_at' => null] + $sound],
            'active but not mapped' => [['mapped' => false] + $sound],
            'none with a login name' => [['credential_status' => 'none', 'has_credential' => false] + $sound],
            'retired but live' => [['credential_status' => 'retired'] + $sound],
            'unreadable instant' => [['credentials_updated_at' => 'the seventh of never'] + $sound],
        ];
    }

    public function test_a_representative_without_an_integration_uid_makes_no_request(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->statusBody())]);

        $representative = $this->representative();
        $representative->forceFill(['integration_uid' => ''])->save();

        $failure = $this->failureFrom(fn () => $this->client()->status($representative));

        $this->assertSame(MasarCredentialFailure::MissingExternalIdentity, $failure->failure);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- create

    public function test_it_creates_a_credential_and_returns_the_one_time_password(): void
    {
        $mutation = (string) Str::uuid();

        $this->fakeToken(['*/credential' => Http::response($this->mutationBody('created', password: 'ONETIMEPASSWORD1234'))]);

        $issued = $this->client()->create($this->representative(), 'omar.badi', $mutation);

        $this->assertInstanceOf(MasarIssuedCredential::class, $issued);
        $this->assertSame('omar.badi', $issued->loginName);
        $this->assertSame('ONETIMEPASSWORD1234', $issued->password);
        $this->assertSame(MasarCredentialState::Active, $issued->state);
        $this->assertSame('2026-10-07 09:00:00', $issued->credentialsUpdatedAt->toDateTimeString());
    }

    public function test_the_create_request_is_built_from_the_model_and_the_callers_two_values(): void
    {
        $mutation = (string) Str::uuid();

        $this->fakeToken(['*/credential' => Http::response($this->mutationBody('created', password: 'ONETIMEPASSWORD1234'))]);

        $representative = $this->representative('Omar Badi', '0911000000');

        $this->client()->create($representative, 'omar.badi', $mutation);

        Http::assertSent(function (Request $request) use ($mutation): bool {
            if ($request->method() !== 'POST' || $request->url() !== self::CREDENTIAL_URL) {
                return false;
            }

            // The identity on the wire is the durable uid and never the primary key.
            return $request->data() === [
                'client_mutation_id' => $mutation,
                'login_name' => 'omar.badi',
                'courier' => ['name' => 'Omar Badi', 'phone' => '0911000000'],
            ];
        });

        // Stated separately because it is the invariant, not an implementation detail.
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), (string) $representative->getKey())
            || str_contains($request->url(), self::UID));
    }

    public function test_a_courier_without_a_phone_sends_null_rather_than_an_empty_string(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->mutationBody('created', password: 'ONETIMEPASSWORD1234'))]);

        $this->client()->create($this->representative('Omar Badi', null), 'omar.badi', (string) Str::uuid());

        Http::assertSent(fn (Request $request): bool => $request->url() === self::CREDENTIAL_URL
            && $request->method() === 'POST'
            && $request->data()['courier'] === ['name' => 'Omar Badi', 'phone' => null]);
    }

    public function test_a_replayed_creation_returns_a_result_with_no_password_property(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->mutationBody('already_applied'))]);

        $outcome = $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid());

        // A distinct type, so a caller cannot show a password it never received
        // (§13.28.11).
        $this->assertInstanceOf(MasarReplayedCredential::class, $outcome);
        $this->assertFalse(property_exists($outcome, 'password'));
        $this->assertSame('omar.badi', $outcome->loginName);
    }

    public function test_a_replay_carrying_a_password_is_a_contract_violation(): void
    {
        $body = $this->mutationBody('already_applied');
        $body['credential']['password'] = 'MASARKEPTTHEPLAINTEXT';

        $this->fakeToken(['*/credential' => Http::response($body)]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        // It would mean Masar had kept the plaintext, which §13.28.10 forbids.
        $this->assertSame(MasarCredentialFailure::ContractViolation, $failure->failure);
        $this->assertStringNotContainsString('MASARKEPTTHEPLAINTEXT', $failure->getMessage());
    }

    public function test_an_applied_creation_without_a_password_is_a_contract_violation(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->mutationBody('created'))]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::ContractViolation, $failure->failure);
    }

    public function test_a_creation_answering_with_the_rotation_word_is_a_contract_violation(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->mutationBody('rotated', password: 'ONETIMEPASSWORD1234'))]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::ContractViolation, $failure->failure);
    }

    #[DataProvider('mutationRefusals')]
    public function test_a_refused_mutation_is_classified_and_sent_once(string $code, MasarCredentialFailure $expected): void
    {
        $this->fakeToken(['*/credential' => Http::response(
            ['success' => false, 'error' => ['code' => $code, 'message' => 'x'], 'request_id' => 'req-9'], 409,
        )]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        $this->assertSame($expected, $failure->failure);
        $this->assertSame($code, $failure->errorCode);
        $this->assertSame('req-9', $failure->requestId);
        $this->assertTrue($failure->isSettled(), 'a refusal must assert that nothing was written');
        $this->assertSame(1, $this->requestsTo(self::CREDENTIAL_URL));
    }

    /** @return array<string, array{0: string, 1: MasarCredentialFailure}> */
    public static function mutationRefusals(): array
    {
        return [
            'already exists' => ['CREDENTIAL_ALREADY_EXISTS', MasarCredentialFailure::CredentialAlreadyExists],
            'retired exists' => ['CREDENTIAL_RETIRED_EXISTS', MasarCredentialFailure::CredentialRetiredExists],
            'nothing to reset' => ['NO_CREDENTIAL_TO_RESET', MasarCredentialFailure::NoCredentialToReset],
            'login name taken' => ['LOGIN_NAME_TAKEN', MasarCredentialFailure::LoginNameTaken],
            'key reused' => ['IDEMPOTENCY_KEY_REUSED', MasarCredentialFailure::IdempotencyKeyReused],
        ];
    }

    public function test_a_validation_refusal_is_classified_without_surfacing_the_field_errors(): void
    {
        $this->fakeToken(['*/credential' => Http::response([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Invalid representative credential request.',
                'fields' => ['login_name' => ['The login name must not begin or end with whitespace.']],
            ],
        ], 422)]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::ValidationError, $failure->failure);
        $this->assertStringNotContainsString('login_name', $failure->getMessage());
        $this->assertStringNotContainsString('whitespace', $failure->getMessage());
    }

    public function test_a_rate_limited_mutation_is_not_retried(): void
    {
        $this->fakeToken(['*/credential' => Http::response(
            ['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Too many integration requests.']],
            429,
            ['Retry-After' => '30'],
        )]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::RateLimited, $failure->failure);

        // Retry-After is deliberately not honoured by waiting and resending: a
        // mutation is sent once per operator action (§13.28.11).
        $this->assertSame(1, $this->requestsTo(self::CREDENTIAL_URL));
    }

    // ------------------------------------------------- the no-retry guarantee

    public function test_a_rejected_token_does_not_cause_a_second_create_post(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => Http::response(['success' => false, 'error' => ['code' => 'AUTH_FAILED', 'message' => 'x']], 401),
        ]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::AuthenticationFailure, $failure->failure);

        // One POST. The read leg refreshes and retries; this one must not.
        $this->assertSame(1, $this->requestsTo(self::CREDENTIAL_URL));
    }

    public function test_a_rejected_token_is_still_invalidated_for_the_next_action(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->push($this->tokenBody('stale-token'))
                ->push($this->tokenBody('fresh-token')),
            '*/credential' => Http::sequence()
                ->push(['success' => false, 'error' => ['code' => 'AUTH_FAILED', 'message' => 'x']], 401)
                ->push($this->mutationBody('created', password: 'ONETIMEPASSWORD1234')),
        ]);

        $client = $this->client();
        $representative = $this->representative();

        $this->failureFrom(fn () => $client->create($representative, 'omar.badi', (string) Str::uuid()));

        // A second, deliberate action — a new operator click with a new identifier —
        // starts with a fresh token rather than the one that was just refused.
        $issued = $client->create($representative, 'omar.badi', (string) Str::uuid());

        $this->assertInstanceOf(MasarIssuedCredential::class, $issued);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_a_timed_out_mutation_is_unknown_and_sent_exactly_once(): void
    {
        $attempts = 0;

        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        // The whole point: unknown, not failed. Masar may have committed.
        $this->assertSame(MasarCredentialFailure::ResultUnknown, $failure->failure);
        $this->assertFalse($failure->isSettled());
        $this->assertSame(1, $attempts);
    }

    public function test_a_server_fault_on_a_mutation_is_unknown_rather_than_failed(): void
    {
        $this->fakeToken(['*/credential' => Http::response(
            ['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'x'], 'request_id' => 'req-500'], 500,
        )]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        // Masar's 500 reports that a fault occurred; it does not report that the
        // transaction rolled back. Telling the operator it failed would invite a
        // second attempt.
        $this->assertSame(MasarCredentialFailure::ResultUnknown, $failure->failure);
        $this->assertFalse($failure->isSettled());
        $this->assertSame(500, $failure->httpStatus);
        $this->assertSame('req-500', $failure->requestId);
        $this->assertSame(1, $this->requestsTo(self::CREDENTIAL_URL));
    }

    public function test_an_unreachable_token_service_makes_no_mutation_request(): void
    {
        Http::fake([
            self::TOKEN_URL => fn () => throw new ConnectionException('down'),
            '*/credential' => Http::response($this->mutationBody('created', password: 'X')),
        ]);

        $failure = $this->failureFrom(fn () => $this->client()->create($this->representative(), 'omar.badi', (string) Str::uuid()));

        // Nothing was posted, so the outcome is known: unavailable, not unknown.
        $this->assertSame(MasarCredentialFailure::Unavailable, $failure->failure);
        $this->assertTrue($failure->isSettled());
        $this->assertSame(0, $this->requestsTo(self::CREDENTIAL_URL));
    }

    // ---------------------------------------------------------------- rotate

    public function test_it_rotates_and_returns_the_one_time_password(): void
    {
        $this->fakeToken(['*/rotation' => Http::response($this->mutationBody('rotated', password: 'NEWONETIMEPASSWORD1'))]);

        $issued = $this->client()->rotate($this->representative(), (string) Str::uuid());

        $this->assertInstanceOf(MasarIssuedCredential::class, $issued);
        $this->assertSame('NEWONETIMEPASSWORD1', $issued->password);
        $this->assertSame('omar.badi', $issued->loginName);
    }

    public function test_the_rotation_body_carries_the_mutation_id_and_nothing_else(): void
    {
        $mutation = (string) Str::uuid();

        $this->fakeToken(['*/rotation' => Http::response($this->mutationBody('rotated', password: 'NEWONETIMEPASSWORD1'))]);

        $this->client()->rotate($this->representative(), $mutation);

        // §13.28.7: the stable name is not sent, and Masar refuses a request that
        // carries one — so an extra key here would fail the call, not merely be
        // ignored.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::ROTATION_URL
            && $request->data() === ['client_mutation_id' => $mutation]);
    }

    public function test_a_replayed_rotation_returns_no_password(): void
    {
        $this->fakeToken(['*/rotation' => Http::response($this->mutationBody('already_applied'))]);

        $outcome = $this->client()->rotate($this->representative(), (string) Str::uuid());

        $this->assertInstanceOf(MasarReplayedCredential::class, $outcome);
        $this->assertFalse(property_exists($outcome, 'password'));
    }

    public function test_an_applied_rotation_without_a_password_is_a_contract_violation(): void
    {
        $this->fakeToken(['*/rotation' => Http::response($this->mutationBody('rotated'))]);

        $failure = $this->failureFrom(fn () => $this->client()->rotate($this->representative(), (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::ContractViolation, $failure->failure);
    }

    public function test_a_rotation_with_nothing_to_reset_is_a_settled_refusal(): void
    {
        $this->fakeToken(['*/rotation' => Http::response(
            ['success' => false, 'error' => ['code' => 'NO_CREDENTIAL_TO_RESET', 'message' => 'x']], 409,
        )]);

        $failure = $this->failureFrom(fn () => $this->client()->rotate($this->representative(), (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::NoCredentialToReset, $failure->failure);
        $this->assertTrue($failure->isSettled());
        $this->assertSame(1, $this->requestsTo(self::ROTATION_URL));
    }

    public function test_a_rejected_token_does_not_cause_a_second_rotation_post(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response(['success' => false, 'error' => ['code' => 'AUTH_FAILED', 'message' => 'x']], 401),
        ]);

        $this->failureFrom(fn () => $this->client()->rotate($this->representative(), (string) Str::uuid()));

        $this->assertSame(1, $this->requestsTo(self::ROTATION_URL));
    }

    public function test_a_timed_out_rotation_is_unknown_and_sent_exactly_once(): void
    {
        $attempts = 0;

        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('timed out');
            },
        ]);

        $failure = $this->failureFrom(fn () => $this->client()->rotate($this->representative(), (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::ResultUnknown, $failure->failure);
        $this->assertSame(1, $attempts);
    }

    public function test_a_rotation_server_fault_is_unknown_and_sent_once(): void
    {
        $this->fakeToken(['*/rotation' => Http::response(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 500)]);

        $failure = $this->failureFrom(fn () => $this->client()->rotate($this->representative(), (string) Str::uuid()));

        $this->assertSame(MasarCredentialFailure::ResultUnknown, $failure->failure);
        $this->assertSame(1, $this->requestsTo(self::ROTATION_URL));
    }

    // ----------------------------------------------------------- local refusals

    public function test_an_inactive_representative_cannot_be_given_a_credential(): void
    {
        $this->fakeToken(['*' => Http::response($this->mutationBody('created', password: 'X'))]);

        $representative = $this->representative();
        $representative->forceFill(['is_active' => false])->save();

        foreach ([
            fn () => $this->client()->create($representative, 'omar.badi', (string) Str::uuid()),
            fn () => $this->client()->rotate($representative, (string) Str::uuid()),
        ] as $call) {
            $failure = $this->failureFrom($call);

            // Mini's own policy, and it says nothing about the Masar credential —
            // Mini activity is not propagated (§13.28.15).
            $this->assertSame(MasarCredentialFailure::RepresentativeInactive, $failure->failure);
        }

        $this->assertSame(0, $this->requestsTo(self::CREDENTIAL_URL));
        $this->assertSame(0, $this->requestsTo(self::ROTATION_URL));
    }

    public function test_an_inactive_representative_may_still_be_read(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ))]);

        $representative = $this->representative();
        $representative->forceFill(['is_active' => false])->save();

        // Showing the state is exactly what an administrator needs in order to
        // decide anything about them.
        $this->assertSame('omar.badi', $this->client()->status($representative)->loginName);
    }

    public function test_a_mutation_for_a_representative_without_an_identity_makes_no_request(): void
    {
        $this->fakeToken(['*' => Http::response($this->mutationBody('created', password: 'X'))]);

        $representative = $this->representative();
        $representative->forceFill(['integration_uid' => ''])->save();

        foreach ([
            fn () => $this->client()->create($representative, 'omar.badi', (string) Str::uuid()),
            fn () => $this->client()->rotate($representative, (string) Str::uuid()),
        ] as $call) {
            $this->assertSame(
                MasarCredentialFailure::MissingExternalIdentity,
                $this->failureFrom($call)->failure,
            );
        }

        $this->assertSame(0, $this->requestsTo(self::CREDENTIAL_URL));
    }

    #[DataProvider('refusedLoginNames')]
    public function test_a_login_name_is_refused_locally_and_never_corrected(string $loginName): void
    {
        $this->fakeToken(['*' => Http::response($this->mutationBody('created', password: 'X'))]);

        $failure = $this->failureFrom(
            fn () => $this->client()->create($this->representative(), $loginName, (string) Str::uuid()),
        );

        $this->assertSame(MasarCredentialFailure::ValidationError, $failure->failure);
        $this->assertSame(0, $this->requestsTo(self::CREDENTIAL_URL));
    }

    /** @return array<string, array{0: string}> */
    public static function refusedLoginNames(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' omar.badi'],
            'trailing space' => ['omar.badi '],
            'both' => [' omar.badi '],
            'tab' => ["\tomar.badi"],
            'too long' => [str_repeat('a', 101)],
        ];
    }

    public function test_a_well_formed_login_name_is_sent_byte_for_byte(): void
    {
        $this->fakeToken(['*/credential' => Http::response($this->mutationBody('created', password: 'X', loginName: 'Omar.Badi-2026_x'))]);

        $this->client()->create($this->representative(), 'Omar.Badi-2026_x', (string) Str::uuid());

        Http::assertSent(fn (Request $request): bool => $request->url() === self::CREDENTIAL_URL
            && $request->method() === 'POST'
            && $request->data()['login_name'] === 'Omar.Badi-2026_x');
    }

    public function test_a_malformed_mutation_identifier_is_refused_without_being_replaced(): void
    {
        $this->fakeToken(['*' => Http::response($this->mutationBody('created', password: 'X'))]);

        $representative = $this->representative();

        foreach ([
            fn () => $this->client()->create($representative, 'omar.badi', 'not-a-uuid'),
            fn () => $this->client()->rotate($representative, 'not-a-uuid'),
        ] as $call) {
            $this->assertSame(MasarCredentialFailure::ValidationError, $this->failureFrom($call)->failure);
        }

        // Generating a fresh one would turn a caller's mistake into a distinct
        // Masar mutation, which is what the identifier exists to prevent.
        $this->assertSame(0, $this->requestsTo(self::CREDENTIAL_URL));
        $this->assertSame(0, $this->requestsTo(self::ROTATION_URL));
    }

    // --------------------------------------------------------------- the outbox

    public function test_no_credential_operation_touches_the_integration_outbox(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('rotated', password: 'NEWONETIMEPASSWORD1')),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody(
                    hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
                ))
                : Http::response($this->mutationBody('created', password: 'ONETIMEPASSWORD1234')),
        ]);

        $representative = $this->representative();

        $this->client()->create($representative, 'omar.badi', (string) Str::uuid());
        $this->client()->rotate($representative, (string) Str::uuid());
        $this->client()->status($representative);

        // Credential management is synchronous and is not an order event
        // (§13.28.19). The outbox could not carry one even if asked: its payload is
        // a stored JSON column, and that would write a password to MySQL.
        $this->assertDatabaseCount('integration_outbox', 0);
        $this->assertDatabaseCount('masar_integration_events', 0);
    }

    // --------------------------------------------------------------- helpers

    private function client(): MasarCredentialClient
    {
        return app(MasarCredentialClient::class);
    }

    private function representative(string $name = 'Omar Badi', ?string $phone = '0911000000'): Representative
    {
        $representative = Representative::query()->create([
            'name' => $name,
            'phone' => $phone,
            'is_active' => true,
        ]);

        // Pinned so the asserted URLs are stable; minted by HasIntegrationUid in
        // ordinary use.
        $representative->forceFill(['integration_uid' => self::UID])->save();

        return $representative->refresh();
    }

    /** @param  array<string, mixed>  $routes */
    private function fakeToken(array $routes): void
    {
        Http::fake([self::TOKEN_URL => Http::response($this->tokenBody())] + $routes);
    }

    /** @return array<string, mixed> */
    private function tokenBody(string $token = 'access-token'): array
    {
        return ['success' => true, 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600];
    }

    /** @return array<string, mixed> */
    private function statusBody(
        bool $mapped = true,
        bool $hasCredential = false,
        ?string $loginName = null,
        string $state = 'none',
        ?string $updatedAt = null,
    ): array {
        return [
            'success' => true,
            'external_courier_id' => self::UID,
            'mapped' => $mapped,
            'has_credential' => $hasCredential,
            'login_name' => $loginName,
            'credential_status' => $state,
            'credentials_updated_at' => $updatedAt,
            'request_id' => 'req-status',
        ];
    }

    /** @return array<string, mixed> */
    private function mutationBody(
        string $status,
        ?string $password = null,
        string $loginName = 'omar.badi',
        string $state = 'active',
    ): array {
        $credential = [
            'login_name' => $loginName,
            'credential_status' => $state,
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

    private function failureFrom(callable $call): MasarCredentialException
    {
        try {
            $call();
        } catch (MasarCredentialException $failure) {
            return $failure;
        }

        $this->fail('the credential operation was expected to fail and did not');
    }

    private function requestsTo(string $url): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === $url)->count();
    }
}
