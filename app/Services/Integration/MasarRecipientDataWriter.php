<?php

namespace App\Services\Integration;

use App\Models\DeliveryOrder;

/**
 * Applies a correction Masar's courier made (CONTRACT §13.8, §13.14 — D7).
 *
 * This class exists to *not* be `DeliveryOrderUpdateService`, for the same
 * reason `MasarDeliveryStatusWriter` beside it exists to not be that service.
 * That service is the local edit path, and its last act is to raise an
 * `order.updated` event into the outbox — correctly, because everything it
 * changes is data Mini Delivery owns and Masar needs to hear about. Routing
 * Masar's own correction through it would send that correction straight back to
 * its author, where it arrives as a new `order_version` and is applied, and
 * announced again, without end. Neither system's idempotency can break that
 * cycle: every lap is genuinely a new event. Only ownership can, and this writer
 * is where ownership is expressed. **Nothing here enqueues anything.**
 *
 * `CustomerUpdateService` is excluded for a second reason on top of that one.
 * The three recipient values land on the order and never on `customers`
 * (§13.14): the shared row is one profile behind every order that person placed,
 * its phone is UNIQUE here, and a correction routed into it would rewrite what
 * every sibling order shows while Masar's announcement named exactly one order.
 * That is the failure D7 was decided to end, and the fix is not a narrower call
 * into the customer path — it is not calling it at all.
 *
 * The version moves with the values it describes and never apart from them
 * (§13.8.2): advancing it without applying would make every later correction
 * look stale, and applying without advancing would let the next stale retry
 * overwrite what was just written.
 */
class MasarRecipientDataWriter
{
    /**
     * @param  DeliveryOrder  $order  already locked by the caller's transaction
     * @param  array<string, string|null>  $changedFields  canonical §13.8.3 paths, already validated
     * @param  int  $dataVersion  the applied version, which becomes the new high-water mark
     * @return array<string, string|null> the columns actually written, for the caller's record
     */
    public function apply(DeliveryOrder $order, array $changedFields, int $dataVersion): array
    {
        $columns = [];

        foreach ($changedFields as $path => $value) {
            // The whitelist is the envelope's, checked once more here rather
            // than trusted from the request: this is the only place a value
            // reaches a column, and a writer that would write whatever it was
            // handed is one refactor away from writing something it should not.
            if (! array_key_exists($path, MasarDataEnvelope::PATHS)) {
                continue;
            }

            $columns[MasarDataEnvelope::PATHS[$path]] = $value;
        }

        // `forceFill` then `save`, on the corrected columns and the version and
        // nothing else. The model's own timestamps move, which is ordinary
        // bookkeeping and not an event of any kind.
        $order->forceFill($columns + ['masar_data_version' => $dataVersion])->save();

        return $columns;
    }
}
