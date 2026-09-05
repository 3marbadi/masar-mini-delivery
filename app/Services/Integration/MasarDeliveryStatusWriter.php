<?php

namespace App\Services\Integration;

use App\Enums\DeliveryStatus;
use App\Models\DeliveryOrder;

/**
 * Applies a status Masar owns (CONTRACT §3.21.10).
 *
 * This class exists to *not* be DeliveryOrderUpdateService. That service is the
 * local edit path, and its last act is to raise an `order.updated` event into
 * the outbox — correctly, because everything it changes is data Mini Delivery
 * owns and Masar needs to hear about. Routing Masar's own announcement through
 * it would send that announcement straight back to its author, which arrives as
 * a new `event_id` at a higher `order_version` and is therefore accepted, and
 * announced again, without end. Neither system's idempotency can break that
 * cycle: every lap is genuinely a new event. Only ownership can, and this
 * writer is where ownership is expressed.
 *
 * So: three fields and nothing else — the status, its reason, and the version
 * that establishes which announcement the pair came from.
 *
 * `result`, `status`, `completed_at` and `cancelled_at` are untouched, and that
 * is a decision rather than an omission. `result` is Mini Delivery's own
 * lifecycle outcome and its vocabulary is binary — `delivered` or
 * `not_delivered` — so it cannot express the difference between a postponement
 * and a return. Deriving one from the other would mean Masar deciding how this
 * company classifies its own history. The mapping is a real open question; it
 * is not one this channel is entitled to answer.
 *
 * `location_completed_at` is untouched for a different reason: nothing in this
 * channel carries a location at all (§3.21.1).
 *
 * The announcement's own instant is not stored either. Mini Delivery has no
 * column for when a Masar result happened, and adding one would put the same
 * fact in two places — the event log beside this already records what arrived
 * and when it was applied.
 */
class MasarDeliveryStatusWriter
{
    /**
     * @param  DeliveryOrder  $order  already locked by the caller's transaction
     * @param  int  $statusVersion  the applied version, which becomes the new high-water mark
     */
    public function apply(DeliveryOrder $order, DeliveryStatus $status, ?string $reason, int $statusVersion): void
    {
        // `forceFill` then `save` on the three owned columns, rather than an
        // update through any service. The model's own timestamps move, which is
        // ordinary bookkeeping and not an event of any kind.
        //
        // The version moves with the state it describes and never apart from it
        // (§3.21.11): advancing it without applying the state would make every
        // later announcement look stale, and applying without advancing would
        // let the next stale retry overwrite what was just written.
        $order->forceFill([
            'delivery_status' => $status->value,
            'status_reason' => $reason,
            'masar_status_version' => $statusVersion,
        ])->save();
    }
}
