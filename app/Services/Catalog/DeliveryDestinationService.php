<?php

namespace App\Services\Catalog;

use App\Enums\FulfilmentKind;
use App\Exceptions\InvalidOrderDestinationException;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;
use App\Services\Integration\DestinationPayload;

/**
 * The one authority on what destination an order may carry, and what it costs
 * (PLAN D2 §5.2, §4.3).
 *
 * Called from `DeliveryOrder`'s `saving` hook, so it runs on every path that
 * saves an order rather than on the single admin page that creates one today.
 * That placement is the whole design, and it follows the recipient snapshot
 * beside it for the same stated reason: a guarantee that lives in a page is a
 * guarantee the second page will not have.
 *
 * ## Two jobs, and they are deliberately in one place
 *
 * **It refuses what must not be stored.** A region that belongs to another city,
 * a city that demands a region when none was given, a withdrawn city or region
 * chosen afresh, a destination changed after Masar was told about the
 * assignment. A foreign key proves `region_id` names *a* region and can prove
 * nothing about the column beside it, so this is where the pairing is checked.
 *
 * **It writes the snapshot itself.** `city_name`, `region_name` and
 * `delivery_fee_lyd` are never taken from the request. Whatever the client sent
 * for them is discarded and the values are read from the catalog, which is what
 * "the backend determines the price" has to mean if it is to survive a crafted
 * form post, a disabled field re-enabled in a browser, or a second UI nobody has
 * written yet.
 *
 * ## When it acts, and why that matters more than what it does
 *
 * It acts only when the destination is being *chosen* — when `city_id` or
 * `region_id` is dirty, or the order is new. Every other save passes through
 * untouched, and three guarantees fall out of that one rule:
 *
 * - **Historic orders stay editable.** An order placed before this catalog
 *   existed has no destination, so nothing is dirty and nothing is checked.
 *   Editing its value or its location link is exactly the operation it always
 *   was, and no backfill is demanded of it.
 * - **A withdrawn city does not freeze the orders that used it.** `is_active` is
 *   checked against the city being selected, not against the one already
 *   stored. So deactivating «طرابلس» tomorrow leaves today's طرابلس orders
 *   viewable, editable and completable; it only stops the next order choosing
 *   it.
 * - **A catalog price change never reaches a saved order.** Repricing is
 *   triggered by the city on the order changing, not by the price in the catalog
 *   changing. An order re-saved for an unrelated reason keeps the fee it was
 *   registered at, which is what PLAN §4.3 means by a central price applying
 *   only to future orders.
 *
 * ## Region-only changes do not reprice
 *
 * Changing the region updates `region_name` and stops. The source file prices
 * cities and not regions, so there is nothing about a region that could change a
 * price — and re-reading the city's price "while we are here" would silently
 * import the catalog's *current* figure into an order that was agreed at an
 * older one. The fee moves when, and only when, the city moves.
 */
class DeliveryDestinationService
{
    /**
     * The three columns this service derives and nobody else may set.
     *
     * Named once because two rules read the same list: the stamp writes them
     * from the catalog, and {@see assertSnapshotIsNotEditedAlone()} refuses them
     * from anywhere else.
     *
     * @var list<string>
     */
    private const SNAPSHOT_COLUMNS = ['city_name', 'region_name', 'delivery_fee_lyd'];

    /**
     * Validate the destination on an order about to be saved, and stamp its
     * snapshot.
     *
     * Mutates the model rather than returning attributes: it is invoked from the
     * `saving` hook, where the model *is* the thing being described, and handing
     * back an array the caller then had to remember to apply would make the
     * guarantee optional again.
     *
     * @throws InvalidOrderDestinationException
     */
    public function stamp(DeliveryOrder $order): void
    {
        $cityChanged = $order->isDirty('city_id');
        $regionChanged = $order->isDirty('region_id');

        // A new record is always stamped, even with no destination at all. That
        // is the case that closes the last hole: without it, a request that
        // posted `delivery_fee_lyd` and no `city_id` would leave `city_id`
        // clean, nothing would be recomputed, and a client-supplied fee would
        // survive onto an order with nowhere to go.
        if ($order->exists && ! $cityChanged && ! $regionChanged) {
            $this->assertSnapshotIsNotEditedAlone($order);

            return;
        }

        $cityId = $this->key($order->city_id);
        $regionId = $this->key($order->region_id);

        if ($cityId === null) {
            if ($regionId !== null) {
                throw new InvalidOrderDestinationException(
                    'A region cannot be stored without the city it belongs to.',
                );
            }

            // No destination. Deliberately clears the snapshot as well: an order
            // whose destination is removed must not keep a fee for a place it is
            // no longer going to.
            $order->forceFill([
                'city_name' => null,
                'region_name' => null,
                'delivery_fee_lyd' => null,
            ]);

            return;
        }

        $this->assertNotAlreadyAnnounced($order, $cityChanged, $regionChanged);

        $city = DeliveryCity::query()->find($cityId);

        if ($city === null) {
            throw new InvalidOrderDestinationException("No delivery city has id [{$cityId}].");
        }

        // Only a *newly chosen* city is held to being offered. The stored one is
        // history, and history does not stop being true when a destination is
        // withdrawn.
        if ($cityChanged) {
            if ($city->fulfilment_kind !== FulfilmentKind::Delivery) {
                throw new InvalidOrderDestinationException(
                    "City [{$city->name}] is {$city->fulfilment_kind->value} and is not an ordinary delivery "
                    .'destination; the office-pickup flow is a separate stage.',
                );
            }

            if (! $city->is_active) {
                throw new InvalidOrderDestinationException(
                    "City [{$city->name}] is not active and cannot be chosen for a destination.",
                );
            }
        }

        $region = null;

        if ($regionId !== null) {
            $region = DeliveryRegion::query()->find($regionId);

            if ($region === null) {
                throw new InvalidOrderDestinationException("No delivery region has id [{$regionId}].");
            }

            // The check a foreign key cannot make. Enforced whenever either half
            // of the pair moves, because changing the city is just as capable of
            // breaking the pairing as changing the region.
            if (! $region->belongsToCity($city->id)) {
                throw new InvalidOrderDestinationException(
                    "Region [{$region->name}] belongs to another city and cannot be used with [{$city->name}].",
                );
            }

            if ($regionChanged && ! $region->is_active) {
                throw new InvalidOrderDestinationException(
                    "Region [{$region->name}] is not active and cannot be chosen for a destination.",
                );
            }
        } elseif ($city->is_region_required) {
            throw new InvalidOrderDestinationException(
                "City [{$city->name}] requires a region, and none was given.",
            );
        }

        // ---- The snapshot, read from the catalog and from nowhere else ----

        if ($cityChanged) {
            // The city moved, so the whole snapshot is re-taken: the names it is
            // going under and the price that city is charged at now. This is the
            // only repricing there is.
            $order->forceFill([
                'city_name' => $city->name,
                'region_name' => $region?->name,
                'delivery_fee_lyd' => $city->delivery_price_lyd,
            ]);

            return;
        }

        // The region moved and the city did not. The name follows the region; the
        // fee is left exactly as it was agreed.
        $order->forceFill(['region_name' => $region?->name]);
    }

    /**
     * Refuses a snapshot edited on its own (D3, closing a D2 gap).
     *
     * **The hole this closes, exactly as it was found.** D2 put the snapshot
     * under the service's control by deriving `city_name`, `region_name` and
     * `delivery_fee_lyd` from the catalog whenever the destination moved — and
     * then returned early when it had not. But the three columns stayed mass
     * assignable, so `$order->update(['delivery_fee_lyd' => '0.01'])` moved
     * neither `city_id` nor `region_id`, took the early return, and wrote a fee
     * nobody approved onto an order whose destination was never touched. The
     * same held for the two names: an order could be made to claim it was going
     * somewhere it was not. Both are reproduced and then refused in
     * `OrderSnapshotIntegrityTest`.
     *
     * **Why here and not by narrowing `$fillable`.** Removing the three from the
     * fillable list would break the fixtures that legitimately pass them at
     * creation — where they are overwritten from the catalog anyway — and would
     * not actually close the hole, because `forceFill()` never consulted
     * `$fillable` in the first place. The rule is "the snapshot is derived, never
     * supplied", and the only place that can hold for every write is the save
     * path. So mass assignment is left exactly as it was and the invariant moved
     * to where saving happens.
     *
     * **It fires only on an existing order whose destination did not move.** A
     * new record is stamped unconditionally a few lines above, so a fee posted
     * with a create is discarded rather than refused — there is no stored value
     * for it to corrupt, and a create that named a city gets the catalog's
     * figures regardless. A legitimate city change reprices under D2's rules,
     * and a region-only change keeps the fee it was agreed at; neither reaches
     * this method.
     *
     * `saveQuietly()` bypasses every model event and therefore this check too.
     * That is Eloquent's documented escape hatch rather than a gap: it is used in
     * tests to construct a state the wire would have produced, and a caller that
     * reaches for it has said in as many words that it wants no domain rules.
     *
     * @throws InvalidOrderDestinationException
     */
    private function assertSnapshotIsNotEditedAlone(DeliveryOrder $order): void
    {
        $edited = array_values(array_filter(
            self::SNAPSHOT_COLUMNS,
            static fn (string $column): bool => $order->isDirty($column),
        ));

        if ($edited === []) {
            return;
        }

        throw new InvalidOrderDestinationException(sprintf(
            'Order [%s] had its destination snapshot (%s) edited without its city or region changing. '
            .'The snapshot is derived from the catalog when the destination is chosen and is never supplied '
            .'by a caller; change the city to reprice, or leave the snapshot alone.',
            (string) $order->getKey(),
            implode(', ', $edited),
        ));
    }

    /**
     * The fee a city would charge, for the form's preview.
     *
     * Display only, and the form shows it through a component that is never
     * dehydrated, so this value cannot become the stored one. {@see stamp()}
     * reads the price again at save time from the same column — the preview is a
     * promise about what will happen, not the mechanism that makes it happen.
     *
     * Null is returned for the four unpriced cities and is not a fee of zero;
     * the caller renders «السعر غير محدد» for it.
     */
    public function previewFee(?int $cityId): ?string
    {
        if ($cityId === null) {
            return null;
        }

        return DeliveryCity::query()->whereKey($cityId)->value('delivery_price_lyd');
    }

    /**
     * Refuses a destination change on an order Masar has already been told
     * about — **unless destination synchronisation is on** (D3 lifted this).
     *
     * **Why it existed.** The destination of an assigned order has already
     * travelled: `order.assigned` carried a snapshot and Masar stores it against
     * that order. Changing the city here would leave the two systems describing
     * one delivery differently, with no event able to reconcile them, because
     * §3.7's `changed_fields` vocabulary had no destination path. The two
     * alternatives were both worse than refusing: send a shape the receiver does
     * not know — which it answers `422`, terminal and never retried (§3.21.7) —
     * or change it locally and say nothing.
     *
     * **What lifted it.** D3 adds the five paths to §3.7 (v5.19), teaches the
     * receiver to apply them, and keeps the whole feature behind
     * `services.masar.destination_sync`. So the condition is no longer "has this
     * been announced" but "can the change be told": with the flag on, a
     * destination edit produces a real `order.updated` carrying
     * `order.destination.*` and, where it moved, `order.delivery_cost`, and the
     * two systems stay in step.
     *
     * **It is kept, rather than deleted, precisely because of the rollout.**
     * While the flag is off this side must behave exactly as D2 did, or the
     * window between the two deployments becomes the one state nobody tested: an
     * edit applied locally and suppressed on the wire. Deleting the method would
     * have created that window. It is removed for good once the flag has been on
     * in production long enough that no deployment can be behind it.
     *
     * Orders never announced are untouched either way: there is no counterpart
     * to disagree with, and editing them emits nothing.
     */
    private function assertNotAlreadyAnnounced(DeliveryOrder $order, bool $cityChanged, bool $regionChanged): void
    {
        if (! $order->exists || ! ($cityChanged || $regionChanged)) {
            return;
        }

        // The lift. With synchronisation on, the change has a path to travel on
        // and `DeliveryOrderUpdateService` puts it there.
        if (DestinationPayload::enabled()) {
            return;
        }

        if (! $order->hasBeenAnnouncedToMasar()) {
            return;
        }

        throw new InvalidOrderDestinationException(
            "Order [{$order->getKey()}] has already been announced to Masar, so its destination cannot be changed "
            .'while destination synchronisation is off. Masar would never learn of the change, and the two systems '
            .'would describe one delivery differently. Enable services.masar.destination_sync once Masar\'s '
            .'receiver is deployed (CONTRACT §3.7, v5.19 — D3).',
        );
    }

    /**
     * A foreign key as an int, or null.
     *
     * Form state arrives as strings, and `''` is what an emptied Select sends —
     * which is a cleared destination, not a city with id zero.
     */
    private function key(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
