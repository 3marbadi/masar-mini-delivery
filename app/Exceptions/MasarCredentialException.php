<?php

namespace App\Exceptions;

use App\Enums\MasarCredentialFailure;
use RuntimeException;

/**
 * A credential operation that produced no credential, classified safely
 * (CONTRACT §13.28.10, §13.28.13).
 *
 * Carries four things and no fifth: the classification the admin panel maps to a
 * sentence, Masar's own error code when there was one, the HTTP status when there
 * was one, and Masar's request id for diagnostics. The request id is not a secret
 * (§13.28.18 lists it among what may be recorded) and is the only value here that
 * connects a complaint to a line in Masar's own log.
 *
 * **What it deliberately does not carry:** the response body, any part of it, the
 * password, the bearer token, the integration client secret, or a previous
 * exception. That last omission is the one most easily undone by habit — passing a
 * `ConnectionException` as `$previous` would put the request, and with it the
 * Authorization header, into every trace this exception appears in. So there is no
 * `$previous` parameter at all, and the message is a fixed sentence per
 * classification rather than anything derived from what Masar sent.
 *
 * The message is for a developer reading a log. The admin panel reads `$failure`
 * and never `getMessage()` — §13.28.16 keeps internal detail off the operator's
 * screen, and a message that happened to quote Masar would put it there.
 */
class MasarCredentialException extends RuntimeException
{
    private function __construct(
        public readonly MasarCredentialFailure $failure,
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    // ------------------------------------------------------- local refusals

    /**
     * No request was made, because there is no identity to make one about.
     *
     * The representative's primary key is deliberately not used instead: Masar
     * resolves its mapping by `integration_uid`, and an auto-increment key is
     * unique inside one incarnation of this database and nowhere else.
     */
    public static function missingExternalIdentity(): self
    {
        return new self(
            MasarCredentialFailure::MissingExternalIdentity,
            'The representative carries no integration_uid, so no Masar credential operation can name it. '
            .'The primary key is deliberately not used as a substitute.',
        );
    }

    /** Mini's own policy, and not a statement about the Masar credential (§13.28.15). */
    public static function representativeInactive(): self
    {
        return new self(
            MasarCredentialFailure::RepresentativeInactive,
            'The representative is inactive at the delivery company, so Mini refuses to create or reset a '
            .'Masar credential for them. This says nothing about the Masar credential itself, which is '
            .'not disabled by Mini activity state (CONTRACT §13.28.15).',
        );
    }

    /**
     * Refused before sending.
     *
     * The offending value is named only when it is a shape rather than content —
     * a login name is not a secret, but it is also not needed here, and the panel
     * has its own validation to say what is wrong.
     */
    public static function invalidInput(string $what): self
    {
        return new self(
            MasarCredentialFailure::ValidationError,
            "The Masar credential request was refused locally before it was sent: {$what}.",
        );
    }

    // ---------------------------------------------------------- Masar answers

    /** A classified refusal from Masar. Nothing was written. */
    public static function refused(
        MasarCredentialFailure $failure,
        ?string $errorCode,
        int $httpStatus,
        ?string $requestId,
    ): self {
        return new self(
            $failure,
            "Masar refused the credential operation [{$failure->value}].",
            $errorCode,
            $httpStatus,
            $requestId,
        );
    }

    /**
     * Masar answered something §13.28 forbids.
     *
     * `$what` names the violated rule, never the value that violated it — a
     * response carrying a password where none belongs must not be described by
     * quoting it.
     */
    public static function contractViolation(string $what, ?int $httpStatus = null, ?string $requestId = null): self
    {
        return new self(
            MasarCredentialFailure::ContractViolation,
            "Masar's credential response violated the contract: {$what}.",
            null,
            $httpStatus,
            $requestId,
        );
    }

    /** Masar could not be reached, on a leg that writes nothing. */
    public static function unavailable(string $stage): self
    {
        return new self(
            MasarCredentialFailure::Unavailable,
            "Masar could not be reached for a credential read ({$stage}).",
        );
    }

    /** A read failed with a server fault. Nothing was written. */
    public static function serverError(int $httpStatus, ?string $errorCode, ?string $requestId): self
    {
        return new self(
            MasarCredentialFailure::ServerError,
            'Masar answered a credential read with a server fault.',
            $errorCode,
            $httpStatus,
            $requestId,
        );
    }

    /**
     * The one classification that does not assert the operation failed.
     *
     * «النتيجةُ مجهولة، لا فاشلة» (§13.28.11). Reached by a timeout, a dropped
     * connection, or a `5xx` on a mutating leg — Masar's `500` reports a fault and
     * does not report a rollback. The caller must not retry; it reads the status
     * and lets a person decide.
     */
    public static function resultUnknown(string $stage, ?int $httpStatus = null, ?string $requestId = null): self
    {
        return new self(
            MasarCredentialFailure::ResultUnknown,
            "A Masar credential mutation was sent and its outcome could not be confirmed ({$stage}). "
            .'It may have been applied. Read the credential status before any further action, and do not '
            .'repeat the operation automatically (CONTRACT §13.28.11).',
            null,
            $httpStatus,
            $requestId,
        );
    }

    /** True when nothing was written and the caller may act on that. */
    public function isSettled(): bool
    {
        return $this->failure->isSettled();
    }
}
