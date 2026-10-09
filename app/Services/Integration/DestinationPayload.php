<?php

namespace App\Services\Integration;

use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;

/**
 * The destination and the delivery fee, as they travel to Masar (CONTRACT §3.7,
 * v5.19 — D3).
 *
 * One class so that the wire shape has one definition on this side. The
 * assignment snapshot, the update's `changed_fields`, and the `current_snapshot`
 * on every later event all read it from here, which is what stops the three from
 * drifting into three slightly different spellings of the same object.
 *
 * ## The identifiers are the catalog's, not this database's
 *
 * `city_id` and `region_id` on the wire are `delivery_cities.source_city_id` and
 * `delivery_regions.source_region_id` — the ids the source CSV gave those
 * records. They are **never** the internal primary keys the order references.
 * That distinction is the whole reason D1 kept two identifiers per row: the
 * internal key is this database's business and may differ in any other
 * deployment, while the source id is the one both companies can name. Masar
 * stores them as `destination_city_external_id` and
 * `destination_region_external_id`, and the names say which kind they are.
 *
 * They are strings on the wire, like `external_order_id` and
 * `external_courier_id` beside them: an external identifier is an opaque label,
 * and nothing on either side does arithmetic with it.
 *
 * ## The names are the order's, not the catalog's
 *
 * `city_name` and `region_name` come from the order's own snapshot columns, so
 * Masar is told what this order was sent to **under the name it was agreed
 * under**. Reading the catalog's current name instead would make a later
 * spelling correction rewrite the description of a delivery that has already
 * happened, which is the thing D2 stored the snapshot to prevent — and it would
 * do it across a system boundary, where it could not be noticed.
 *
 * ## Staged rollout
 *
 * Everything here is gated on `services.masar.destination_sync`, default off.
 * Masar's receiver is deployed first and accepts both shapes; only then is this
 * switched on. While it is off the payloads are byte-for-byte what they were
 * before D3, and the destination cannot be edited on an announced order either —
 * the two halves share the flag deliberately, because allowing the edit while
 * the fields are suppressed is exactly the silent divergence D2's restriction
 * existed to stop.
 */
final class DestinationPayload
{
    /**
     * The five `changed_fields` paths D3 adds to §3.7's vocabulary.
     *
     * Declared in the order a reader expects them and used for the whitelist,
     * the classification on Masar's side, and the tests. `order.delivery_cost`
     * sits with them because a fee moves only when a city moves — it is part of
     * the same act — but it is a sibling of `destination`, not a member of it,
     * because the fee belongs to the order and not to the place.
     *
     * @var list<string>
     */
    public const PATHS = [
        'order.delivery_cost',
        'order.destination.city_id',
        'order.destination.city_name',
        'order.destination.region_id',
        'order.destination.region_name',
    ];

    /** Whether this deployment sends the new fields at all (staged rollout). */
    public static function enabled(): bool
    {
        return (bool) config('services.masar.destination_sync', false);
    }

    /**
     * The destination and fee keys for an order snapshot, or none.
     *
     * Returns an empty array when the rollout flag is off, so the caller merges
     * nothing and the envelope is identical to the pre-D3 one.
     *
     * **An order with no destination still carries the keys, with nulls.** Every
     * order placed before D1 has none, and `destination: null` states that
     * plainly. The alternative — omitting the keys — would mean "no information"
     * rather than "no destination", and §3.7 is explicit that an absent key and
     * a present null are different claims. On an assignment the distinction is
     * moot, but the same builder fills `current_snapshot`, where it decides
     * whether Masar leaves a stored value alone or clears it.
     *
     * @return array<string, mixed>
     */
    public static function forSnapshot(DeliveryOrder $order): array
    {
        if (! self::enabled()) {
            return [];
        }

        return [
            // Decimal string or null. Null is not a fee of zero: it is one of the
            // four cities the catalog prices at nothing, and Masar stores the
            // null rather than inventing a figure.
            'delivery_cost' => $order->delivery_fee_lyd,
            'destination' => self::destination($order),
        ];
    }

    /**
     * The `destination` object for an order, or null when it has none.
     *
     * @return array<string, string|null>|null
     */
    public static function destination(DeliveryOrder $order): ?array
    {
        if ($order->city_id === null) {
            return null;
        }

        return [
            'city_id' => self::citySourceId((int) $order->city_id),
            // The snapshot, not the catalog. See the class note.
            'city_name' => $order->city_name,
            'region_id' => $order->region_id === null
                ? null
                : self::regionSourceId((int) $order->region_id),
            'region_name' => $order->region_name,
        ];
    }

    /**
     * The `changed_fields` entries for a destination move, with old and new.
     *
     * Only the paths that actually moved. A region-only change declares the two
     * region paths and nothing else — declaring `order.delivery_cost` as well,
     * with equal old and new, would tell Masar a price changed when none did, and
     * §3.7 requires every declared path to be a real difference.
     *
     * The `old` values are resolved from the order's original attributes, which
     * means a lookup of the previous city and region by their internal keys.
     * Those rows are still there: the catalog never deletes, and the orders table
     * restricts on delete, so the previous destination can always be named.
     *
     * @param  array<string, mixed>  $original  the order's attributes before this save
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function changes(DeliveryOrder $order, array $original): array
    {
        if (! self::enabled()) {
            return [];
        }

        $oldCityId = self::key($original['city_id'] ?? null);
        $oldRegionId = self::key($original['region_id'] ?? null);
        $newCityId = self::key($order->city_id);
        $newRegionId = self::key($order->region_id);

        $changes = [];

        if ($oldCityId !== $newCityId) {
            $changes['order.destination.city_id'] = [
                'old' => $oldCityId === null ? null : self::citySourceId($oldCityId),
                'new' => $newCityId === null ? null : self::citySourceId($newCityId),
            ];
        }

        if ($oldRegionId !== $newRegionId) {
            $changes['order.destination.region_id'] = [
                'old' => $oldRegionId === null ? null : self::regionSourceId($oldRegionId),
                'new' => $newRegionId === null ? null : self::regionSourceId($newRegionId),
            ];
        }

        // The names are declared from the snapshot columns rather than derived
        // from the ids above, because they are what changed on this order. A city
        // renamed in the catalog does not reach a stored order at all (D2), so a
        // name path here always accompanies an id path — but it is read from the
        // column so the value sent is the value stored.
        foreach (['city_name', 'region_name'] as $column) {
            $old = $original[$column] ?? null;
            $new = $order->getAttribute($column);

            if ($old !== $new) {
                $changes['order.destination.'.$column] = ['old' => $old, 'new' => $new];
            }
        }

        $oldFee = self::money($original['delivery_fee_lyd'] ?? null);
        $newFee = self::money($order->delivery_fee_lyd);

        if ($oldFee !== $newFee) {
            $changes['order.delivery_cost'] = ['old' => $oldFee, 'new' => $newFee];
        }

        return $changes;
    }

    /**
     * The catalog source id of a city, as a string.
     *
     * Cached nowhere and queried per call: these run once per event, inside the
     * transaction that is already holding the order, and a cache here would be a
     * second place for the mapping to be wrong.
     */
    private static function citySourceId(int $cityId): ?string
    {
        $sourceId = DeliveryCity::query()->whereKey($cityId)->value('source_city_id');

        return $sourceId === null ? null : (string) $sourceId;
    }

    private static function regionSourceId(int $regionId): ?string
    {
        $sourceId = DeliveryRegion::query()->whereKey($regionId)->value('source_region_id');

        return $sourceId === null ? null : (string) $sourceId;
    }

    private static function key(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * A fee as it goes on the wire: a two-decimal string, or null.
     *
     * Normalised so that `15.00` read back from the database and `15.00` read
     * from an original attribute array compare equal. Without it a fee that did
     * not change could be declared as a change because one side held `15.0`.
     */
    private static function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }
}
