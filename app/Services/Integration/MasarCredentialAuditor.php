<?php

namespace App\Services\Integration;

use App\Enums\MasarCredentialFailure;
use App\Exceptions\MasarCredentialException;
use App\Models\Representative;
use Illuminate\Support\Facades\Log;

/**
 * The record of who did what to a courier's login (CONTRACT §13.28.18).
 *
 * A class of its own rather than a few `Log::info()` calls inside the client, for
 * one reason: §13.28.18 is a list of what may be recorded and a list of what must
 * never be, and a rule of that shape deserves exactly one place where it is
 * decided. Scattered through a transport class, the decision would be re-made at
 * every call site and would eventually be re-made wrongly.
 *
 * **The signatures are the containment.** Every method takes scalars and a
 * Representative — none takes a MasarIssuedCredential, a response, or an array of
 * unspecified context — so there is no parameter a password could arrive in. The
 * client has to pick out the safe fields to call this at all, which is the point:
 * the narrow interface makes the careless call impossible rather than merely
 * discouraged.
 *
 * What is recorded: the acting administrator where one is known, the
 * representative and the identity Masar knows them by, the login name, the
 * mutation identifier, Masar's request id, and the outcome. `login_name` is in
 * that list on purpose — it is an identifier and not a secret, and without it the
 * record cannot answer «أيُّ حسابٍ صُفِّر».
 *
 * What is never recorded: the password, any hash, the bearer token, the client
 * secret, the response body.
 *
 * Masar is not told any of this. The record belongs to the company's own tool,
 * because Masar authenticates the company and not a person inside it (§13.28.18),
 * so the acting administrator is knowable only here.
 */
final class MasarCredentialAuditor
{
    /** A read of a courier's credential state. */
    public function statusRead(Representative $representative, ?int $actorId, MasarCredentialStatus $status): void
    {
        Log::info('masar.credential.status_read', $this->subject($representative, $actorId) + [
            'mapped' => $status->mapped,
            'has_credential' => $status->hasCredential,
            'credential_status' => $status->state->value,
            'login_name' => $status->loginName,
        ]);
    }

    /**
     * A first credential was issued.
     *
     * `$loginName` is passed rather than read off a credential object, so no
     * object holding a password is ever in scope here.
     */
    public function created(
        Representative $representative,
        ?int $actorId,
        string $loginName,
        string $clientMutationId,
        ?string $requestId,
        bool $replayed,
    ): void {
        Log::info('masar.credential.created', $this->mutation(
            $representative, $actorId, $loginName, $clientMutationId, $requestId, $replayed,
        ));
    }

    /** A password was reset. The login name is unchanged by contract (§13.28.7). */
    public function rotated(
        Representative $representative,
        ?int $actorId,
        string $loginName,
        string $clientMutationId,
        ?string $requestId,
        bool $replayed,
    ): void {
        Log::info('masar.credential.rotated', $this->mutation(
            $representative, $actorId, $loginName, $clientMutationId, $requestId, $replayed,
        ));
    }

    /**
     * An operation that produced no credential.
     *
     * Logged at warning for a settled refusal and at error for an unknown result,
     * because the two call for different attention: one is an answer, the other is
     * an open question about production state that somebody has to close
     * (§13.28.11).
     */
    public function failed(
        Representative $representative,
        ?int $actorId,
        string $operation,
        MasarCredentialException $failure,
        ?string $clientMutationId = null,
    ): void {
        $context = $this->subject($representative, $actorId) + [
            'operation' => $operation,
            'outcome' => 'failed',
            'failure' => $failure->failure->value,
            'settled' => $failure->isSettled(),
            'error_code' => $failure->errorCode,
            'http_status' => $failure->httpStatus,
            'masar_request_id' => $failure->requestId,
            'client_mutation_id' => $clientMutationId,
        ];

        if ($failure->failure === MasarCredentialFailure::ResultUnknown) {
            Log::error('masar.credential.failed', $context);

            return;
        }

        Log::warning('masar.credential.failed', $context);
    }

    /** @return array<string, mixed> */
    private function mutation(
        Representative $representative,
        ?int $actorId,
        string $loginName,
        string $clientMutationId,
        ?string $requestId,
        bool $replayed,
    ): array {
        return $this->subject($representative, $actorId) + [
            'login_name' => $loginName,
            'client_mutation_id' => $clientMutationId,
            'masar_request_id' => $requestId,
            // The distinction §13.28.11 draws: an operation performed now, or one
            // recognised as already performed. A replay issued no new password.
            'outcome' => $replayed ? 'already_applied' : 'applied',
        ];
    }

    /** @return array<string, mixed> */
    private function subject(Representative $representative, ?int $actorId): array
    {
        return [
            // Null when the operation did not come from a panel session — a
            // console run or a test. Recorded as null rather than guessed at.
            'admin_user_id' => $actorId,
            'representative_id' => $representative->getKey(),
            'integration_uid' => $representative->integration_uid,
        ];
    }
}
