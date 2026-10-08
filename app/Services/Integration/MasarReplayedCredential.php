<?php

namespace App\Services\Integration;

use App\Enums\MasarCredentialState;
use Carbon\CarbonImmutable;

/**
 * A credential operation that had already been performed (CONTRACT §13.28.11).
 *
 * Masar recognised the `client_mutation_id` as one it has already spent and
 * answered `already_applied`. The operation happened — once — and the password it
 * produced went out with the response nobody received.
 *
 * **It has no password property, and that is the entire reason it is a separate
 * type from MasarIssuedCredential.** §13.28.11 is explicit that idempotency does
 * not make the plaintext replayable: «المعرّفُ المُعاد يقول قد نُفِّذ ولا يقول بأيّ
 * سرّ» — storing it so a replay could return it would make the secret recoverable
 * after the fact, which §13.28.10 forbids outright.
 *
 * The caller therefore cannot show a password here even by mistake, and the union
 * return type on MasarCredentialClient's mutating methods forces every caller to
 * notice which of the two it received. That is what §17 is asking for: downstream
 * code must know a first success from a replay, because the two call for different
 * things on screen — one shows a credential, the other says the reset already
 * happened and the password is gone.
 */
final readonly class MasarReplayedCredential
{
    public function __construct(
        public string $loginName,
        public MasarCredentialState $state,
        public ?CarbonImmutable $credentialsUpdatedAt,
    ) {}
}
