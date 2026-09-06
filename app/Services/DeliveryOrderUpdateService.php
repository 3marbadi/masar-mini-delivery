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

            if ($changes === []) {
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

            if (($lockedOrder->integrationState?->current_version ?? 0) >= 1) {
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
