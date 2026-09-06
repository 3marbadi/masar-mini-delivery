<?php

namespace App\Services\Integration;

use App\Models\DeliveryOrder;
use Illuminate\Support\Carbon;

/**
 * Applies one completed location from Masar (CONTRACT §13.17.4).
 *
 * This class exists to *not* be `DeliveryOrderUpdateService`, and on this channel
 * that is not a stylistic parallel with its three siblings — it is the single
 * load-bearing barrier, and §13.17.4 calls it «أشدُّ ما في هذه القناة».
 *
 * The danger is concrete rather than theoretical. `DeliveryOrderUpdateService`
 * lists `latitude` and `longitude` among the four fields it publishes, and its
 * last act is to raise `order.updated` into the outbox. `location.latitude` and
 * `location.longitude` are two of the seven paths Masar accepts inbound (§3.7),
 * and applying one there creates an `OrderChange` of type `location` and, behind
 * it, a reevaluation. So a location applied through that service would go back
 * to Masar as a change Masar itself had just made, be applied, be announced
 * again — and nothing would stop it, because every lap is a genuinely new event
 * with a valid identity and an advancing version. Neither system's idempotency
 * can break that cycle. Only ownership can, and this writer is where ownership
 * is expressed. **Nothing here enqueues anything.**
 *
 * The coordinates are written as the strings they arrived as (§13.17.1). The
 * column is DECIMAL(10,7) and Masar normalised to seven places before sending;
 * casting to float on the way in would reintroduce the representation error the
 * string form exists to avoid.
 *
 * The version and the stamp move with the values they describe and never apart
 * from them (§13.17.2, §13.17.5): advancing either without applying would make
 * later announcements look stale, and applying without advancing would let the
 * next delayed retry overwrite what was just written.
 */
class MasarLocationWriter
{
    /**
     * @param  DeliveryOrder  $order  already locked by the caller's transaction
     * @param  array<string, mixed>  $data  the validated `data` block of the event
     * @return array<string, string> the columns actually written, for the caller's record
     */
    public function apply(DeliveryOrder $order, array $data, int $locationVersion, string $eventId): array
    {
        $columns = [
            'latitude' => (string) $data['latitude'],
            'longitude' => (string) $data['longitude'],
            'location_completed_at' => Carbon::parse((string) $data['location_completed_at'])->utc(),
            // §13.17.5, D13 — the accepted stamp, taken from the event rather
            // than from the clock here. It records when the change happened at
            // Masar, which is what the next comparison needs; stamping it with
            // our own arrival time would make every delayed announcement look
            // like the newest thing that ever happened.
            'location_changed_at' => Carbon::parse((string) $data['location_changed_at'])->utc(),
            'location_change_source' => (string) $data['location_change_source'],
            'location_change_event_id' => $eventId,
        ];

        // `forceFill` then `save`, on these columns and the version and nothing
        // else. `location_validation_status` is deliberately untouched:
        // §13.17.1 keeps it off the wire because it is Masar's judgement about
        // its own engine's needs, and inventing a value for it here would be
        // this system asserting something it was never told.
        //
        // The model's own timestamps move, which is ordinary bookkeeping and not
        // an event of any kind.
        $order->forceFill($columns + ['masar_location_version' => $locationVersion])->save();

        return [
            'latitude' => $columns['latitude'],
            'longitude' => $columns['longitude'],
        ];
    }
}
