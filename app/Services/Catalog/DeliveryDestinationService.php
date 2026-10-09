<?php

namespace App\Services\Catalog;

use App\Enums\FulfilmentKind;
use App\Exceptions\InvalidOrderDestinationException;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;

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
     * about — the one restriction D3 exists to lift.
     *
     * **Why it has to be here and not only in the form.** The destination of an
     * assigned order has already travelled: `order.assigned` carried a snapshot,
     * and Masar stores it against that order. Changing the city here would leave
     * the two systems describing the same delivery differently, with no event
     * able to reconcile them — `order.updated` has no destination paths in the
     * v1.0 contract, so there is nothing truthful to send. The alternatives are
     * both worse than refusing: send an event whose shape the receiver does not
     * know, or change it locally and say nothing.
     *
     * **What D3 replaces this with.** Destination fields in `changed_fields` and
     * `current_snapshot`, a receiver that understands them, and a staged rollout
     * in which Masar is deployed first. Once an `order.updated` can carry the
     * change, this refusal becomes a restriction with no reason behind it and
     * should be deleted in the same commit that adds the paths.
     *
     * Orders never announced are untouched by this: there is no counterpart to
     * disagree with, and editing them emits nothing.
     */
    private function assertNotAlreadyAnnounced(DeliveryOrder $order, bool $cityChanged, bool $regionChanged): void
    {
        if (! $order->exists || ! ($cityChanged || $regionChanged)) {
            return;
        }

        if (! $order->hasBeenAnnouncedToMasar()) {
            return;
        }

        throw new InvalidOrderDestinationException(
            "Order [{$order->getKey()}] has already been announced to Masar, so its destination cannot be changed. "
            .'The v1.0 event contract carries no destination paths, so the change could not be transmitted; '
            .'D3 adds them and lifts this restriction.',
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
