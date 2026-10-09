<?php

namespace App\Services\Integration;

use App\Enums\TourParticipation;
use App\Models\DeliveryOrder;
use Illuminate\Support\Carbon;

/**
 * Applies one participation statement Masar owns (CONTRACT §13.29 — D31, draft;
 * §3.21.10).
 *
 * This class exists to *not* be `DeliveryOrderUpdateService`, for exactly the
 * reason `MasarDeliveryStatusWriter` and `MasarRecipientDataWriter` beside it
 * exist to not be that service. That service is the local edit path, and its
 * last act is to raise an `order.updated` event into the outbox — correctly,
 * because everything it changes is data Mini Delivery owns and Masar needs to
 * hear about. Routing Masar's own statement through it would send that statement
 * straight back to its author, where it arrives at a higher `order_version` and
 * is therefore accepted, and announced again, without end. Neither system's
 * idempotency can break that cycle: every lap is genuinely a new event. Only
 * ownership can, and this writer is where ownership is expressed. **Nothing
 * here enqueues anything.**
 *
 * **What it does not touch, and why each omission is a decision.**
 *
 * `status`, `result`, `completed_at` and `cancelled_at` are this company's own
 * lifecycle and CONTRACT.md line 4105 is explicit that the receiver «لا يمسّ
 * `result` ولا `status` ولا `completed_at`» — a reconciliation debt on this
 * side, not a licence for the channel to settle it. `reception_rate` is
 * computed from two of them, is sent to Masar in every outbound envelope, and
 * is relayed there without recomputation; a write here would rewrite every
 * customer's history and the figure Masar holds along with it.
 *
 * `delivery_status`, `status_reason` and `masar_status_version` belong to the
 * status channel. Participation and result are two different facts about one
 * order, and §13.12 forbids ordering either by the other's sequence. An order
 * can be `delivered` and still carry a live participation; the projection
 * resolves which to show, and it resolves it by precedence rather than by
 * overwriting one with the other.
 *
 * `representative_id` is not touched either. A participation statement names the
 * courier who held the order when Masar made it, and that is recorded in
 * `masar_tour_started_representative_id` for audit — it never moves the
 * assignment. Reassignment is this company's act alone.
 *
 * **Why the two history instants are not cleared.** `masar_participation_changed_at`
 * is overwritten by every transition, so an `ended` would otherwise erase when
 * the tour had begun. `masar_tour_started_at` and `masar_tour_ended_at`
 * accumulate instead: the first keeps its value through the ending, and the
 * previous tour's own start remains in `masar_integration_events` with the
 * version that carried it.
 */
class MasarTourParticipationWriter
{
    /**
     * @param  DeliveryOrder  $order  already locked by the caller's transaction
     * @param  int  $participationVersion  the applied version, which becomes the new high-water mark
     * @param  int|null  $baseOrderVersion  the `order_version` Masar built this statement on
     * @param  string|null  $courierUid  the uid exactly as it arrived, kept whether or not it resolves
     * @param  int|null  $representativeId  that uid resolved locally, or null when it names no row here
     */
    public function apply(
        DeliveryOrder $order,
        TourParticipation $participation,
        int $participationVersion,
        ?int $baseOrderVersion,
        ?string $courierUid,
        ?int $representativeId,
        ?string $tourReference,
        ?Carbon $tourDepartureAt,
        Carbon $changedAt,
    ): void {
        $columns = [
            'masar_participation' => $participation->value,
            'masar_participation_version' => $participationVersion,
            'masar_participation_base_order_version' => $baseOrderVersion,
            'masar_participation_changed_at' => $changedAt,
            'masar_tour_reference' => $tourReference,
            'masar_tour_departure_at' => $tourDepartureAt,
            // Both, always. The uid is what Masar said and is kept even when it
            // resolves to nothing, so the audit can name the courier and a later
            // repair can re-resolve it. The local id is the resolution, and the
            // projection reads it: an order will not read as being delivered
            // while the courier who began the participation cannot be named, or
            // is not the one it is currently assigned to.
            'masar_tour_started_courier_uid' => $courierUid,
            'masar_tour_started_representative_id' => $representativeId,
        ];

        // The two accumulating instants, each written only by the transition it
        // belongs to. `scheduled` moves neither: nothing has begun and nothing
        // has ended, and stamping either would assert something the event does
        // not say.
        //
        // The instant is the event's `occurred_at` — **when Masar announced the
        // transition**, which is what the column names say and all that D31
        // guarantees. It is not `delivery_tours.started_at`, and it is not a
        // claim about when the courier physically set off: in the "depart now"
        // path the tour's own start is written at `POST /tours`, seconds before
        // the bootstrap whose sealing raises this event. Nothing reads either
        // column as evidence of a departure, and the operational projection
        // reads neither at all.
        if ($participation === TourParticipation::Active) {
            $columns['masar_participation_started_at'] = $changedAt;
        }

        if ($participation === TourParticipation::Ended) {
            $columns['masar_participation_ended_at'] = $changedAt;
        }

        // `forceFill` then `save` on the channel's own columns and nothing else.
        // The model's own timestamps move, which is ordinary bookkeeping and not
        // an event of any kind.
        //
        // The version moves with the state it describes and never apart from it:
        // advancing it without applying the state would make every later
        // statement look stale, and applying without advancing would let the
        // next stale retry overwrite what was just written.
        $order->forceFill($columns)->save();
    }
}
