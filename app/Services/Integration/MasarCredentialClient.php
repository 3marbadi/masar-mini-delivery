<?php

namespace App\Services\Integration;

use App\Enums\MasarCredentialFailure;
use App\Enums\MasarCredentialState;
use App\Exceptions\MasarCredentialException;
use App\Models\Representative;
use App\Services\MasarAccessTokenProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mini's half of the credential channel (CONTRACT §13.28.2, §13.28.4).
 *
 * Three calls on the integration channel this application already authenticates
 * on, and nothing about how a page looks: the admin panel hands this a
 * representative and gets back a value object or an exception it can classify.
 * §13.28.2 fixes the chain — admin browser → this backend → the authenticated
 * server-to-server channel → Masar — and nothing here is reachable from a
 * browser, nor does the integration secret leave the token provider.
 *
 * **The read and the two mutations are deliberately not symmetrical**, and that
 * asymmetry is the most important thing in this file:
 *
 *   - `status()` is safe to repeat (§13.28.11: «وقراءةُ الحال تُعاد بلا حرجٍ كسائر
 *     القراءات»), so a stale token is refreshed and the GET is retried **once**;
 *   - `create()` and `rotate()` send **exactly one HTTP request, ever**. No retry
 *     on a timeout, a dropped connection, a `5xx` or a `401`. Masar is idempotent,
 *     and a blind repeat is still forbidden: §13.28.11 says the first attempt may
 *     have committed while its answer was lost, and «أضرُّ ما في البابِ سرٌّ أُنشئ
 *     ولم يَعلم به أحد» — a second rotation invalidates a password that was issued
 *     and delivered. A `401` invalidates the cached token for the *next*
 *     deliberate action and this one still fails.
 *
 * So an ambiguous mutation is reported as MasarCredentialFailure::ResultUnknown
 * rather than as a failure, and the only thing that resolves it is a status read
 * followed by a human decision.
 *
 * Nothing here writes to the database. No outbox row, no column, no cache entry,
 * no session value — Masar remains the sole authority for credentials
 * (§13.28.3), and `integration_outbox` could not carry one of these even if asked:
 * its payload is a stored JSON column, and storing a response body here would
 * write a password to MySQL.
 *
 * Responses are validated rather than trusted. A `200` from the right URL is not
 * evidence of the right shape, and §13.28.10's promise that a replay carries no
 * password is only worth as much as the check that refuses one that does.
 */
final class MasarCredentialClient
{
    /** The widest a login name may be (§9.1 of the Masar contract). */
    private const LOGIN_NAME_LIMIT = 100;

    private const APPLIED_CREATE = 'created';

    private const APPLIED_ROTATE = 'rotated';

    private const REPLAYED = 'already_applied';

    public function __construct(
        private readonly MasarAccessTokenProvider $tokens,
        private readonly MasarCredentialAuditor $audit,
    ) {}

    /**
     * Reads a courier's credential state. Writes nothing, anywhere.
     *
     * @param  int|null  $actorId  the administrator acting, for the record. Masar authenticates the
     *                             company and not a person, so this is knowable only here
     *
     * @throws MasarCredentialException
     */
    public function status(Representative $representative, ?int $actorId = null): MasarCredentialStatus
    {
        $externalCourierId = $this->externalCourierId($representative, 'status', $actorId);

        try {
            $response = $this->read($externalCourierId);
        } catch (MasarCredentialException $failure) {
            $this->audit->failed($representative, $actorId, 'status', $failure);

            throw $failure;
        }

        if (! $response->successful()) {
            $failure = $this->readFailure($response);
            $this->audit->failed($representative, $actorId, 'status', $failure);

            throw $failure;
        }

        try {
            $status = $this->parseStatus($externalCourierId, $response);
        } catch (MasarCredentialException $failure) {
            $this->audit->failed($representative, $actorId, 'status', $failure);

            throw $failure;
        }

        $this->audit->statusRead($representative, $actorId, $status);

        return $status;
    }

    /**
     * Issues a courier's first credential.
     *
     * The request body is built from the model and from the caller's two values,
     * and from nothing else: the identity, the name and the phone are read off the
     * Representative rather than accepted as arguments, because the model is the
     * authority for them and an argument would be a second way to name a courier.
     *
     * @param  string  $loginName  the operator's choice, passed through untouched
     * @param  string  $clientMutationId  one per operator action — see rotate()
     *
     * @throws MasarCredentialException
     */
    public function create(
        Representative $representative,
        string $loginName,
        string $clientMutationId,
        ?int $actorId = null,
    ): MasarIssuedCredential|MasarReplayedCredential {
        $externalCourierId = $this->externalCourierId($representative, 'create', $actorId, $clientMutationId);

        $this->assertActive($representative, 'create', $actorId, $clientMutationId);
        $this->assertMutationId($representative, $clientMutationId, 'create', $actorId);
        $this->assertLoginName($representative, $loginName, $clientMutationId, $actorId, 'create');

        return $this->mutate(
            $representative,
            $actorId,
            'create',
            $this->credentialUrl($externalCourierId),
            [
                'client_mutation_id' => $clientMutationId,
                'login_name' => $loginName,
                'courier' => [
                    'name' => (string) $representative->name,
                    'phone' => $representative->phone,
                ],
            ],
            self::APPLIED_CREATE,
            $externalCourierId,
            $clientMutationId,
        );
    }

    /**
     * Replaces a courier's password, keeping their login name.
     *
     * The body carries the mutation identifier alone. §13.28.7 is explicit that
     * the name is not sent — «ولا يَطلب `login_name`، لأنّ الثابتَ لا يُرسَل» — and
     * Masar refuses a request that includes one, so sending it "for clarity" would
     * fail the call.
     *
     * The identifier is used exactly as given and never regenerated. A fresh one
     * per attempt would turn one operator action into two distinct Masar
     * mutations, which is the failure the identifier exists to prevent.
     *
     * @throws MasarCredentialException
     */
    public function rotate(
        Representative $representative,
        string $clientMutationId,
        ?int $actorId = null,
    ): MasarIssuedCredential|MasarReplayedCredential {
        $externalCourierId = $this->externalCourierId($representative, 'rotate', $actorId, $clientMutationId);

        $this->assertActive($representative, 'rotate', $actorId, $clientMutationId);
        $this->assertMutationId($representative, $clientMutationId, 'rotate', $actorId);

        return $this->mutate(
            $representative,
            $actorId,
            'rotate',
            $this->rotationUrl($externalCourierId),
            ['client_mutation_id' => $clientMutationId],
            self::APPLIED_ROTATE,
            $externalCourierId,
            $clientMutationId,
        );
    }

    // ------------------------------------------------------------- the read leg

    /**
     * One GET, with a single retry after refreshing a rejected token.
     *
     * The retry is permitted here and only here: the leg writes nothing, so
     * repeating it cannot repeat an effect. Bounded at one — a second `401` is an
     * answer about the integration credentials and not a stale cache, and looping
     * on it would turn a configuration problem into a request storm.
     */
    private function read(string $externalCourierId): Response
    {
        $url = $this->credentialUrl($externalCourierId);

        $response = $this->get($url, $this->token('token'));

        if ($response->status() !== 401) {
            return $response;
        }

        $this->tokens->invalidate();

        return $this->get($url, $this->token('token refresh'));
    }

    private function get(string $url, string $token): Response
    {
        try {
            return Http::withToken($token)
                ->acceptJson()
                ->timeout($this->readTimeout())
                ->get($url);
        } catch (ConnectionException) {
            // Nothing was written, so this is a plain unavailability rather than an
            // open question. The exception is not carried as `$previous`: its
            // request holds the Authorization header.
            throw MasarCredentialException::unavailable('read');
        }
    }

    // --------------------------------------------------------- the mutating legs

    /**
     * One POST. Never two.
     *
     * Every failure mode below ends the operation. A `401` invalidates the cached
     * token so the operator's *next* deliberate action starts clean, and still
     * fails this one; a timeout or a dropped connection becomes ResultUnknown; a
     * `5xx` becomes ResultUnknown too, because Masar's `500` reports that a fault
     * occurred and does not report that the transaction rolled back.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws MasarCredentialException
     */
    private function mutate(
        Representative $representative,
        ?int $actorId,
        string $operation,
        string $url,
        array $body,
        string $appliedStatus,
        string $externalCourierId,
        string $clientMutationId,
    ): MasarIssuedCredential|MasarReplayedCredential {
        try {
            $token = $this->token('token');
        } catch (MasarCredentialException $failure) {
            // The token leg failed, so no mutation was sent. Proven not attempted,
            // and therefore not unknown.
            $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

            throw $failure;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->mutationTimeout())
                ->post($url, $body);
        } catch (ConnectionException) {
            $failure = MasarCredentialException::resultUnknown('connection');
            $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

            throw $failure;
        }

        if (! $response->successful()) {
            $failure = $this->mutationFailure($response);
            $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

            throw $failure;
        }

        try {
            $outcome = $this->parseMutation($externalCourierId, $appliedStatus, $response);
        } catch (MasarCredentialException $failure) {
            $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

            throw $failure;
        }

        $replayed = $outcome instanceof MasarReplayedCredential;
        $requestId = $this->requestId($response);

        $operation === 'create'
            ? $this->audit->created($representative, $actorId, $outcome->loginName, $clientMutationId, $requestId, $replayed)
            : $this->audit->rotated($representative, $actorId, $outcome->loginName, $clientMutationId, $requestId, $replayed);

        return $outcome;
    }

    // ------------------------------------------------------------- classification

    /** A failed read: nothing was written, whatever the status. */
    private function readFailure(Response $response): MasarCredentialException
    {
        $status = $response->status();
        $code = $this->errorCode($response);
        $requestId = $this->requestId($response);

        if ($status === 401) {
            // Already invalidated and retried once in read(); a second refusal is
            // about the credentials themselves.
            return MasarCredentialException::refused(
                MasarCredentialFailure::AuthenticationFailure, $code, $status, $requestId,
            );
        }

        return match (true) {
            $status === 403 => MasarCredentialException::refused(MasarCredentialFailure::ForbiddenClient, $code, $status, $requestId),
            $status === 429 => MasarCredentialException::refused(MasarCredentialFailure::RateLimited, $code, $status, $requestId),
            default => MasarCredentialException::serverError($status, $code, $requestId),
        };
    }

    /**
     * A failed mutation, classified by what the status proves.
     *
     * The four-and-twenty refusals below all prove the same thing — nothing was
     * written. A `401` and a `403` are decided by middleware, a `429` by the rate
     * limiter before the controller runs, and a `409` or `422` by the controller
     * instead of the write. Only the server faults are ambiguous, and they are the
     * one branch that does not claim the operation failed.
     */
    private function mutationFailure(Response $response): MasarCredentialException
    {
        $status = $response->status();
        $code = $this->errorCode($response);
        $requestId = $this->requestId($response);

        if ($status === 401) {
            // For the next deliberate action, not for a retry of this one.
            $this->tokens->invalidate();

            return MasarCredentialException::refused(
                MasarCredentialFailure::AuthenticationFailure, $code, $status, $requestId,
            );
        }

        if ($status >= 500) {
            return MasarCredentialException::resultUnknown('server fault', $status, $requestId);
        }

        $failure = match ($code) {
            'CREDENTIAL_ALREADY_EXISTS' => MasarCredentialFailure::CredentialAlreadyExists,
            'CREDENTIAL_RETIRED_EXISTS' => MasarCredentialFailure::CredentialRetiredExists,
            'NO_CREDENTIAL_TO_RESET' => MasarCredentialFailure::NoCredentialToReset,
            'LOGIN_NAME_TAKEN' => MasarCredentialFailure::LoginNameTaken,
            'IDEMPOTENCY_KEY_REUSED' => MasarCredentialFailure::IdempotencyKeyReused,
            'VALIDATION_ERROR' => MasarCredentialFailure::ValidationError,
            'FORBIDDEN_CLIENT' => MasarCredentialFailure::ForbiddenClient,
            default => null,
        };

        if ($failure !== null) {
            return MasarCredentialException::refused($failure, $code, $status, $requestId);
        }

        // A status we classify without needing the code, then an unrecognised
        // refusal — a Masar we do not know the vocabulary of. Still settled: a 4xx
        // on these legs is a refusal, and refusals write nothing (§13.28.12).
        return match (true) {
            $status === 403 => MasarCredentialException::refused(MasarCredentialFailure::ForbiddenClient, $code, $status, $requestId),
            $status === 422 => MasarCredentialException::refused(MasarCredentialFailure::ValidationError, $code, $status, $requestId),
            $status === 429 => MasarCredentialException::refused(MasarCredentialFailure::RateLimited, $code, $status, $requestId),
            default => MasarCredentialException::refused(MasarCredentialFailure::ServerError, $code, $status, $requestId),
        };
    }

    // ------------------------------------------------------------------ parsing

    /**
     * The read response, checked against §13.28.5 rather than trusted.
     *
     * @throws MasarCredentialException
     */
    private function parseStatus(string $externalCourierId, Response $response): MasarCredentialStatus
    {
        $body = $this->body($response);

        // The key must not exist at all — «لا قيمةً ولا عدماً» (§13.28.5). A null
        // one would mean a schema that knows about passwords and happens to be
        // empty, which is the thing the contract refuses to allow.
        if (array_key_exists('password', $body)) {
            throw MasarCredentialException::contractViolation(
                'the credential status response carried a password key',
                $response->status(),
                $this->requestId($response),
            );
        }

        $this->assertSubject($externalCourierId, $body, $response);

        $mapped = $this->boolean($body, 'mapped', $response);
        $hasCredential = $this->boolean($body, 'has_credential', $response);
        $state = $this->state($body['credential_status'] ?? null, $response);
        $loginName = $this->nullableString($body, 'login_name', $response);
        $updatedAt = $this->instant($body, 'credentials_updated_at', $response);

        $this->assertStatusCoherent($mapped, $hasCredential, $state, $loginName, $updatedAt, $response);

        return new MasarCredentialStatus(
            externalCourierId: $externalCourierId,
            mapped: $mapped,
            hasCredential: $hasCredential,
            loginName: $loginName,
            state: $state,
            credentialsUpdatedAt: $updatedAt,
        );
    }

    /**
     * A mutation response, and the one place the password rule is enforced.
     *
     * An applied operation must carry a non-empty password; a replay must carry no
     * password key at all. Both directions are violations, and both are refused
     * rather than smoothed over: a replay with a password would mean Masar had
     * kept the plaintext, and an applied operation without one would mean handing
     * an administrator a credential nobody can use.
     *
     * @throws MasarCredentialException
     */
    private function parseMutation(
        string $externalCourierId,
        string $appliedStatus,
        Response $response,
    ): MasarIssuedCredential|MasarReplayedCredential {
        $body = $this->body($response);
        $status = $response->status();
        $requestId = $this->requestId($response);

        $this->assertSubject($externalCourierId, $body, $response);

        $reported = $body['status'] ?? null;

        if (! is_string($reported) || ! in_array($reported, [$appliedStatus, self::REPLAYED], true)) {
            // Includes the case of a create answering `rotated` or the reverse:
            // the operation Masar says it performed must be the one that was asked
            // for, or the caller is being told about something else.
            throw MasarCredentialException::contractViolation(
                'the credential response reported an unexpected status', $status, $requestId,
            );
        }

        $credential = $body['credential'] ?? null;

        if (! is_array($credential)) {
            throw MasarCredentialException::contractViolation(
                'the credential response carried no credential object', $status, $requestId,
            );
        }

        $loginName = $credential['login_name'] ?? null;

        if (! is_string($loginName) || $loginName === '') {
            throw MasarCredentialException::contractViolation(
                'the credential response carried no login name', $status, $requestId,
            );
        }

        $state = $this->state($credential['credential_status'] ?? null, $response);
        $updatedAt = $this->instant($credential, 'credentials_updated_at', $response);

        if ($reported === self::REPLAYED) {
            if (array_key_exists('password', $credential)) {
                throw MasarCredentialException::contractViolation(
                    'a replayed credential operation carried a password', $status, $requestId,
                );
            }

            return new MasarReplayedCredential($loginName, $state, $updatedAt);
        }

        $password = $credential['password'] ?? null;

        if (! is_string($password) || $password === '') {
            throw MasarCredentialException::contractViolation(
                'an applied credential operation carried no password', $status, $requestId,
            );
        }

        if ($state !== MasarCredentialState::Active) {
            throw MasarCredentialException::contractViolation(
                'an applied credential operation reported a credential that is not active', $status, $requestId,
            );
        }

        return new MasarIssuedCredential($loginName, $password, $state, $updatedAt);
    }

    // ------------------------------------------------------------- shape checks

    /**
     * The body, as an array.
     *
     * @return array<string, mixed>
     *
     * @throws MasarCredentialException
     */
    private function body(Response $response): array
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            // The decoded body is not carried into the message: §13.28.10 keeps it
            // out of logs, and an exception message is a log line.
            throw MasarCredentialException::contractViolation(
                'the credential response was not valid JSON', $response->status(), null,
            );
        }

        if (! is_array($body) || ($body['success'] ?? null) !== true) {
            throw MasarCredentialException::contractViolation(
                'the credential response was not a successful integration envelope',
                $response->status(),
                is_array($body) ? $this->requestId($response) : null,
            );
        }

        return $body;
    }

    /**
     * The answer must be about the courier that was asked about.
     *
     * Cheap, and it closes a whole class of confusion: a response that described a
     * different courier would otherwise be rendered as this one's, and the
     * administrator would read somebody else's login name on this page.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws MasarCredentialException
     */
    private function assertSubject(string $externalCourierId, array $body, Response $response): void
    {
        if (($body['external_courier_id'] ?? null) !== $externalCourierId) {
            throw MasarCredentialException::contractViolation(
                'the credential response described a different courier',
                $response->status(),
                $this->requestId($response),
            );
        }
    }

    /**
     * The four shapes §13.28.5 describes, and no fifth.
     *
     * Not pedantry: the panel decides which button to offer from these fields
     * together, so an incoherent combination — `active` with no login name, say —
     * would render a credential section that cannot be acted on.
     *
     * @throws MasarCredentialException
     */
    private function assertStatusCoherent(
        bool $mapped,
        bool $hasCredential,
        MasarCredentialState $state,
        ?string $loginName,
        ?CarbonImmutable $updatedAt,
        Response $response,
    ): void {
        $incoherent = match ($state) {
            MasarCredentialState::Active => ! $mapped || ! $hasCredential || $loginName === null || $updatedAt === null,
            MasarCredentialState::Retired => ! $mapped || $hasCredential || $loginName === null || $updatedAt === null,
            MasarCredentialState::None => $hasCredential || $loginName !== null,
        };

        if ($incoherent) {
            throw MasarCredentialException::contractViolation(
                'the credential status fields contradicted each other',
                $response->status(),
                $this->requestId($response),
            );
        }
    }

    /** @param  array<string, mixed>  $body */
    private function boolean(array $body, string $key, Response $response): bool
    {
        $value = $body[$key] ?? null;

        if (! is_bool($value)) {
            throw MasarCredentialException::contractViolation(
                "the credential response field [{$key}] was not a boolean",
                $response->status(),
                $this->requestId($response),
            );
        }

        return $value;
    }

    /** @param  array<string, mixed>  $body */
    private function nullableString(array $body, string $key, Response $response): ?string
    {
        $value = $body[$key] ?? null;

        if ($value !== null && (! is_string($value) || $value === '')) {
            throw MasarCredentialException::contractViolation(
                "the credential response field [{$key}] was not a string or null",
                $response->status(),
                $this->requestId($response),
            );
        }

        return $value;
    }

    /** The closed lexicon of §13.28.5; anything else is a Masar we do not know. */
    private function state(mixed $value, Response $response): MasarCredentialState
    {
        $state = is_string($value) ? MasarCredentialState::tryFrom($value) : null;

        if ($state === null) {
            throw MasarCredentialException::contractViolation(
                'the credential response reported a credential status outside the contract lexicon',
                $response->status(),
                $this->requestId($response),
            );
        }

        return $state;
    }

    /** @param  array<string, mixed>  $body */
    private function instant(array $body, string $key, Response $response): ?CarbonImmutable
    {
        $value = $body[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw MasarCredentialException::contractViolation(
                "the credential response field [{$key}] was not an instant",
                $response->status(),
                $this->requestId($response),
            );
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw MasarCredentialException::contractViolation(
                "the credential response field [{$key}] was not a readable instant",
                $response->status(),
                $this->requestId($response),
            );
        }
    }

    // --------------------------------------------------------------- local gates

    /**
     * The identity the wire uses, or a refusal that costs no request.
     *
     * @throws MasarCredentialException
     */
    private function externalCourierId(
        Representative $representative,
        string $operation,
        ?int $actorId,
        ?string $clientMutationId = null,
    ): string {
        $uid = (string) ($representative->integration_uid ?? '');

        if ($uid === '') {
            $failure = MasarCredentialException::missingExternalIdentity();
            $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

            throw $failure;
        }

        return $uid;
    }

    /**
     * Mini's own refusal for an inactive representative (§13.28.15).
     *
     * Enforced here and not only in the panel, because a hidden button is a
     * display rule: anything that can reach this service — a console command, a
     * future job, a developer in Tinker — bypasses the panel entirely. The read
     * leg is deliberately not gated, since showing an inactive courier's
     * credential state is exactly what an administrator needs in order to decide
     * anything about them.
     *
     * @throws MasarCredentialException
     */
    private function assertActive(
        Representative $representative,
        string $operation,
        ?int $actorId,
        string $clientMutationId,
    ): void {
        if ($representative->is_active) {
            return;
        }

        $failure = MasarCredentialException::representativeInactive();
        $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

        throw $failure;
    }

    /**
     * One operator action, one identifier — and it must be the one given.
     *
     * Validated rather than replaced: generating a fresh identifier on a malformed
     * one would turn a caller's mistake into a second distinct Masar mutation,
     * which is precisely what the identifier exists to prevent.
     *
     * @throws MasarCredentialException
     */
    private function assertMutationId(
        Representative $representative,
        string $clientMutationId,
        string $operation,
        ?int $actorId,
    ): void {
        if (Str::isUuid($clientMutationId)) {
            return;
        }

        $failure = MasarCredentialException::invalidInput('the client mutation id is not a uuid');
        $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

        throw $failure;
    }

    /**
     * The operator's login name, checked and never corrected.
     *
     * Masar refuses a padded name rather than trimming it, because a name quietly
     * corrected is a name the courier will be given and will fail to type
     * (§13.28.6). Mini applies the same rule locally — which saves a round trip and,
     * more importantly, means Mini never produces a request Masar must refuse for
     * a reason Mini could have stated.
     *
     * @throws MasarCredentialException
     */
    private function assertLoginName(
        Representative $representative,
        string $loginName,
        string $clientMutationId,
        ?int $actorId,
        string $operation,
    ): void {
        $problem = match (true) {
            $loginName === '' => 'the login name is empty',
            trim($loginName) !== $loginName => 'the login name begins or ends with whitespace',
            mb_strlen($loginName) > self::LOGIN_NAME_LIMIT => 'the login name is longer than '.self::LOGIN_NAME_LIMIT.' characters',
            default => null,
        };

        if ($problem === null) {
            return;
        }

        $failure = MasarCredentialException::invalidInput($problem);
        $this->audit->failed($representative, $actorId, $operation, $failure, $clientMutationId);

        throw $failure;
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * The shared integration token, and the shared failure vocabulary around it.
     *
     * MasarAccessTokenProvider owns the client credentials, the token endpoint, the
     * cache key and the expiry; none of that is restated here. Its RuntimeException
     * means no request was made — a misconfiguration or an unreachable token
     * service — so it is `unavailable` on both legs and never `result_unknown`:
     * a mutation that never left this process cannot have been applied.
     *
     * Its message is deliberately dropped rather than wrapped. It carries no
     * secret, but it is Masar-internal phrasing and §13.28.16 keeps that off the
     * operator's screen.
     *
     * @throws MasarCredentialException
     */
    private function token(string $stage): string
    {
        try {
            return $this->tokens->token();
        } catch (RuntimeException) {
            throw MasarCredentialException::unavailable($stage);
        }
    }

    private function credentialUrl(string $externalCourierId): string
    {
        return $this->baseUrl().$this->representativesPath().'/'.rawurlencode($externalCourierId).'/credential';
    }

    private function rotationUrl(string $externalCourierId): string
    {
        return $this->credentialUrl($externalCourierId).'/rotation';
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.masar.base_url'), '/');

        if ($base === '') {
            // The same refusal the token provider would reach, stated before a URL
            // is built out of nothing.
            throw MasarCredentialException::unavailable('configuration');
        }

        return $base;
    }

    private function representativesPath(): string
    {
        return '/'.trim((string) config('services.masar.representatives_path', '/api/v1/integration/representatives'), '/');
    }

    private function readTimeout(): int
    {
        return max(1, (int) config('services.masar.credential_read_timeout', 5));
    }

    private function mutationTimeout(): int
    {
        return max(1, (int) config('services.masar.credential_mutation_timeout', 10));
    }

    /** Masar's own request id, for diagnostics. Not a secret (§13.28.18). */
    private function requestId(Response $response): ?string
    {
        $value = $response->json('request_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The machine-readable code, which is the authority — never the message. */
    private function errorCode(Response $response): ?string
    {
        $value = $response->json('error.code');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
