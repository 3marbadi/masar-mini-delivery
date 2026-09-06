<?php

namespace App\Exceptions;

use DomainException;

/**
 * An order reached event generation with no recipient of its own
 * (CONTRACT §13.14 — v5.1, D7).
 *
 * Thrown rather than repaired, and that is the whole point of the class. The
 * obvious repair — read the shared customer profile instead — is precisely what
 * D7 forbids: it re-couples the per-order recipient to a row every order of that
 * person shares, so an order would announce a name a *different* order's history
 * explains. A snapshot that is absent is absent, and the system says so.
 *
 * This should be unreachable in ordinary operation. Every row that existed when
 * the snapshot columns were added was backfilled, and every order created since
 * carries a recipient. Reaching it means a row was inserted by a path that does
 * not know about §13.14, and the right outcome is a loud failure at the moment
 * of generation — inside the transaction, so the local change rolls back with it
 * — rather than an envelope Masar will refuse for a `customer.name` it requires
 * and did not get.
 */
class MissingOrderRecipientException extends DomainException
{
    public static function for(int|string $orderId): self
    {
        return new self(
            "Delivery order [{$orderId}] carries no recipient snapshot, so no integration event can describe its recipient. "
            .'The shared customer profile is deliberately not used as a substitute (CONTRACT §13.14, D7).'
        );
    }
}
