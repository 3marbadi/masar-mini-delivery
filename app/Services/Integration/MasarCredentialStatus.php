<?php

namespace App\Services\Integration;

use App\Enums\MasarCredentialState;
use Carbon\CarbonImmutable;

/**
 * What Masar reports about a courier's login, and nothing more (CONTRACT
 * §13.28.5).
 *
 * Six values. There is no password property — not a null one — because §13.28.5
 * is explicit that the read schema does not know the key: «لا مفتاحَ لكلمة السرّ
 * في مخطّط هذا الردّ: لا قيمةً ولا عدماً». A nullable field here would be a slot
 * for a future reader to fill, and the point is that there is nowhere to put one.
 *
 * Nor does it carry Masar's internal representative id or the integration client
 * id. Mini names couriers by `integration_uid` and has no use for Masar's primary
 * keys; carrying one would invite the identity confusion the wire format exists
 * to prevent.
 *
 * **This object is not credential state Mini owns.** It is the answer to one
 * question asked at one moment, built to be rendered and dropped. D29 keeps Masar
 * the sole authority (§13.28.3) and says the delivery company «تسأل وتَعرض ولا
 * تحفظ» — so nothing persists this, and there is no column it would fit in.
 */
final readonly class MasarCredentialStatus
{
    public function __construct(
        /** The courier's durable identity, as it was asked about. */
        public string $externalCourierId,
        /** Whether Masar holds a courier of this identity for this client. */
        public bool $mapped,
        /** Whether that courier has a *live* credential — not merely a history. */
        public bool $hasCredential,
        /** The login the courier types, or none. Present for a retired credential too. */
        public ?string $loginName,
        public MasarCredentialState $state,
        /** When the credential itself last changed (§3.20.5), or none. */
        public ?CarbonImmutable $credentialsUpdatedAt,
    ) {}

    /**
     * No courier of this identity is known to Masar yet.
     *
     * The ordinary first state of every representative the company creates, since
     * Masar only learns of a courier from an order event or from the credential
     * creation itself (§13.28.6) — which is why §13.28.5 answers it `200` and why
     * nothing here treats it as a fault.
     */
    public function isUnmapped(): bool
    {
        return ! $this->mapped;
    }

    /** A credential exists but is not live, so the courier cannot sign in. */
    public function isRetiredOnly(): bool
    {
        return $this->state === MasarCredentialState::Retired;
    }

    /** Whether a first credential is the operation this courier needs. */
    public function needsFirstCredential(): bool
    {
        return $this->state === MasarCredentialState::None;
    }
}
