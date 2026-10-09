<?php

namespace App\Services;

use App\Models\DeliveryOrder;
use App\Services\Integration\LocationChangeStamp;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * This system's own edit path for the fields it publishes to Masar.
 *
 * Since D13 it also stamps a location change (CONTRACT §13.17.5, §13.17.8). The
 * location is shared-write and the arbiter between the two systems is *when*
 * each change happened at its origin — so a change made here carrying no stamp
 * would lose to every Masar completion for ever, including ones that happened
 * long before it.
 *
 * The stamp and the announcement's `occurred_at` are one instant, taken once and
 * handed to both. They have to be: Masar compares the instant on the wire
 * against the stamp it holds, so a system that stamped one moment and announced
 * another would have the two sides ordering the same change by different clocks.
 */
class DeliveryOrderUpdateService
{
    private const FIELDS = ['value', 'location_link', 'latitude', 'longitude'];

    /**
     * The destination, which this system edits locally and does **not** publish
     * (D2; PLAN §6).
     *
     * Kept out of {@see FIELDS} on purpose, so these two can never appear in
     * `changed_fields`. The v1.0 contract has no destination paths, and inventing
     * them here would hand the receiver a shape it does not know — exactly what
     * the staged rollout in PLAN §8 exists to prevent. There is nothing to send
     * in any case: `DeliveryDestinationService` refuses a destination change on
     * an order Masar has already been told about, so the only orders whose
     * destination can still move are ones no event was ever minted for.
     *
     * D3 adds the paths, teaches the receiver, and moves these two into the
     * published set.
     */
    private const DESTINATION_FIELDS = ['city_id', 'region_id'];

    /** The paths whose movement is a location change (§13.17.8). */
    private const LOCATION_PATHS = ['location.location_link', 'location.latitude', 'location.longitude'];

    private const PATHS = [
        'value' => 'order.amount',
        'location_link' => 'location.location_link',
        'latitude' => 'location.latitude',
        'longitude' => 'location.longitude',
    ];

    public function __construct(
        private IntegrationEventGenerationService $events,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function update(DeliveryOrder $order, array $attributes): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $attributes): DeliveryOrder {
            $lockedOrder = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $lockedOrder->fill(Arr::only($attributes, self::FIELDS));

            // Filled separately and never added to `$changes`. The names and the
            // fee are deliberately *not* filled from the request at all: the
            // model's `saving` hook derives them from the catalog, so a crafted
            // post carrying its own `delivery_fee_lyd` changes nothing.
            $lockedOrder->fill(Arr::only($attributes, self::DESTINATION_FIELDS));

            $destinationChanged = $lockedOrder->isDirty(self::DESTINATION_FIELDS);

            $changes = [];

            foreach (self::FIELDS as $field) {
                if (! $lockedOrder->isDirty($field)) {
                    continue;
                }

                $changes[self::PATHS[$field]] = [
                    'old' => $this->serialize($lockedOrder->getOriginal($field), $field),
                    'new' => $this->serialize($lockedOrder->getAttribute($field), $field),
                ];
            }

            // Nothing moved at all, so nothing is written: no save, no event, no
            // `order_version`, no outbox row. Re-saving a form whose values are
            // unchanged must leave the destination snapshot and the fee exactly
            // as they were agreed, and the cheapest way to guarantee that is not
            // to touch the row (PLAN §5.2.7).
            //
            // The destination is checked *beside* `$changes` rather than inside
            // it: a region-only edit publishes nothing, so it would leave
            // `$changes` empty and return here with the edit silently dropped.
            if ($changes === [] && ! $destinationChanged) {
                return $lockedOrder;
            }

            // One instant for the change and for the announcement of it
            // (§13.17.5). Calling now() twice would let them differ by a second,
            // and the two sides would then order this one change differently.
            $changedAt = Carbon::now();

            // §13.17.8, D13 — a location change made here is stamped here, at the
            // moment it commits, so Masar can tell it from an older completion of
            // its own. Written on the same save as the values it describes: a
            // stamp advanced without the values, or values without the stamp,
            // would each leave the two systems disagreeing about what is current.
            $locationChanged = array_intersect(self::LOCATION_PATHS, array_keys($changes)) !== [];

            if ($locationChanged) {
                $lockedOrder->forceFill([
                    'location_changed_at' => $changedAt,
                    'location_change_source' => LocationChangeStamp::SOURCE_DELIVERY_COMPANY,
                    // Cleared, then filled in below with the announcement's id —
                    // the identity Masar tie-breaks on for this same change. Held
                    // to a local flag rather than re-derived from the row, so a
                    // data-only edit can never adopt an earlier location change's
                    // empty slot as its own.
                    'location_change_event_id' => null,
                ]);
            }

            $lockedOrder->save();
            $lockedOrder->load(['customer', 'representative', 'integrationState']);

            // `$changes` is re-checked here and not only above. A destination-only
            // edit reaches this point with nothing to publish, and an
            // `order.updated` carrying an empty `changed_fields` would raise
            // `order_version` and put a row in the outbox to announce nothing.
            // It cannot happen today — such an edit is only permitted on an order
            // no event was ever minted for, so the version test below already
            // fails — but that is a coupling between two services, and this is
            // the condition itself.
            if ($changes !== [] && $lockedOrder->hasBeenAnnouncedToMasar()) {
                $event = $this->events->updated($lockedOrder, $changes, $changedAt);

                if ($locationChanged) {
                    $lockedOrder->forceFill(['location_change_event_id' => $event->event_id])->save();
                }
            }

            return $lockedOrder->refresh();
        });
    }

    private function serialize(mixed $value, string $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'value' => number_format((float) $value, 2, '.', ''),
            'latitude', 'longitude' => number_format((float) $value, 7, '.', ''),
            default => $value,
        };
    }
}
