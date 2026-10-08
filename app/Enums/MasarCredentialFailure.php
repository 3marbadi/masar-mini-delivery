<?php

namespace App\Enums;

/**
 * Why a credential operation did not produce a credential (CONTRACT §13.28.13).
 *
 * This is the vocabulary the admin panel will map to Arabic sentences, so it is
 * deliberately a closed set of *classifications* rather than a pass-through of
 * Masar's error codes. Several Masar codes collapse into one here — an operator
 * can act on "Masar refused this because the courier already has a login" and
 * cannot act on the difference between a 401 and a 403 — and two local refusals
 * have no Masar code at all because no request was ever sent.
 *
 * **The one distinction that carries real weight is `ResultUnknown` against
 * everything else.** Every other value asserts that nothing was written: a
 * refusal is decided before or instead of the write, and the local ones never
 * reached the network. `ResultUnknown` asserts the opposite — that Masar may have
 * committed and the answer was lost — and §13.28.11 says what follows from it:
 * «النتيجةُ مجهولة، لا فاشلة. فتُقرَأ حالُ الاعتماد … ثمّ يُقرَّر بشرٌ». Telling an
 * administrator a reset failed when it may have succeeded invites them to reset
 * again, and a second rotation invalidates a password that was issued and
 * delivered. That is the costliest mistake available in this feature, so the two
 * meanings never share a value.
 */
enum MasarCredentialFailure: string
{
    // ---------------------------------------------------------------- local

    /**
     * The representative carries no durable integration identity.
     *
     * Unreachable in ordinary operation — HasIntegrationUid mints one on every
     * create — so this means a row arrived by a path that predates it or a
     * corrupted restore. Emphatically **not** repaired by falling back to the
     * primary key: Masar keys its mapping on this value, and a reused integer
     * resolves confidently to the wrong courier.
     */
    case MissingExternalIdentity = 'missing_external_identity';

    /**
     * The representative is inactive at the delivery company.
     *
     * Mini's own policy and not Masar's (§13.28.15): the activity flag is not
     * propagated, so this says nothing about whether the Masar credential is
     * disabled — it is not. Enforced here as well as in the panel because
     * hiding a button is a display rule and never an authorisation boundary.
     */
    case RepresentativeInactive = 'representative_inactive';

    // -------------------------------------------------------------- refusals

    /** Masar refused the input, or Mini refused it before sending (§13.28.13). */
    case ValidationError = 'validation_error';

    case CredentialAlreadyExists = 'credential_already_exists';

    case CredentialRetiredExists = 'credential_retired_exists';

    case NoCredentialToReset = 'no_credential_to_reset';

    case LoginNameTaken = 'login_name_taken';

    case IdempotencyKeyReused = 'idempotency_key_reused';

    // ------------------------------------------------------------- transport

    /** The integration credentials were refused. Nothing was written. */
    case AuthenticationFailure = 'authentication_failure';

    /** The integration client is not permitted. Nothing was written. */
    case ForbiddenClient = 'forbidden_client';

    /** Too many calls. Refused by the rate limiter before the operation. */
    case RateLimited = 'rate_limited';

    /** Masar could not be reached at all, on a leg that writes nothing. */
    case Unavailable = 'unavailable';

    /**
     * A mutation whose outcome is genuinely unknown.
     *
     * Reached by a timeout, a dropped connection, or a `5xx` on a mutating leg —
     * that last one included deliberately, because Masar's `500` says a fault
     * occurred and does **not** say the transaction rolled back. The remedy is
     * always the same: read the credential status, then let a person decide.
     */
    case ResultUnknown = 'result_unknown';

    /** A read failed in a way that proves nothing was written. */
    case ServerError = 'server_error';

    /**
     * Masar answered something its own contract forbids.
     *
     * A replay carrying a password, an applied operation without one, a status
     * outside the lexicon, a body that is not the shape §13.28 describes. Refused
     * rather than interpreted: guessing at a missing value here would mean
     * presenting an administrator with a credential nobody issued.
     */
    case ContractViolation = 'contract_violation';

    /** Whether this classification proves the operation did not happen. */
    public function isSettled(): bool
    {
        return $this !== self::ResultUnknown;
    }
}
