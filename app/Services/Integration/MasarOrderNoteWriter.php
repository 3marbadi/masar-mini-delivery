<?php

namespace App\Services\Integration;

use App\Models\DeliveryOrder;
use App\Models\MasarOrderNote;
use Illuminate\Support\Carbon;

/**
 * Stores one note a Masar courier wrote (CONTRACT §13.16.4, §13.16.7).
 *
 * This class exists to *not* be `DeliveryOrderUpdateService`, for the same
 * reason `MasarDeliveryStatusWriter` and `MasarRecipientDataWriter` beside it
 * exist to not be that service. That service is the local edit path, and its
 * last act is to raise an `order.updated` event into the outbox — correctly,
 * because everything it changes is data Mini Delivery owns and Masar needs to
 * hear about. Routing Masar's own note through anything that enqueues would send
 * it straight back to its author. **Nothing here enqueues anything.**
 *
 * It also does not touch `delivery_orders` at all, and that is deliberate rather
 * than incidental. §13.16.4 keeps the two notions of "note" apart in both
 * directions: what this system sends outbound as `order.notes` is its own remark
 * on its own order, replaced wholesale by whoever edits it here, with no author
 * and no instant. Writing a courier's note into that column would put it on the
 * next `order.updated` we emit, where Masar would apply it as an inbound change
 * to `integration_order_mappings.notes` — and every announcement would then
 * produce an inbound event, without end.
 *
 * Append-only, mirroring the source (§13.5): one row per note, never rewritten.
 * The uniqueness that makes a re-announcement safe lives on the table, not here
 * — the processor decides what a duplicate identity means before this is called.
 */
class MasarOrderNoteWriter
{
    /**
     * @param  DeliveryOrder  $order  already locked by the caller's transaction
     * @param  array<string, mixed>  $data  the validated `data` block of the event
     */
    public function apply(DeliveryOrder $order, array $data, string $eventId, Carbon $received): MasarOrderNote
    {
        return MasarOrderNote::create([
            'delivery_order_id' => $order->getKey(),
            // §13.16.1 — the identity at Masar. Stored as sent; never compared
            // with another note's to decide which is newer (§13.16.2).
            'masar_note_id' => (int) $data['note_id'],
            'content' => (string) $data['content'],
            // Provenance, not a foreign key. This system has no representative
            // that this id names, and §13.16.1 is explicit that we neither
            // require nor resolve it.
            'masar_representative_id' => (int) $data['representative_id'],
            // When the courier wrote it — from the payload, never from now().
            'occurred_at' => Carbon::parse((string) $data['created_at'])->utc(),
            'masar_event_id' => $eventId,
            // When we stored it. A different question from the one above, and
            // both are worth being able to answer.
            'created_at' => $received,
        ]);
    }
}
