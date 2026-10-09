<?php

namespace App\Models;

use App\Enums\FulfilmentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery destination and the price of reaching it (PLAN D1 §4.1).
 *
 * Identified to the outside world by `source_city_id`, the id the source file
 * gave it, and to orders by the internal `id`. Those two jobs are kept apart on
 * purpose: imports match on the first so a renamed city stays one row, and
 * orders reference the second so a re-import can never move an order's
 * destination.
 *
 * `is_active` and `fulfilment_kind` both keep a city out of the order form and
 * mean entirely different things — one that a destination was withdrawn, the
 * other that the row is not a destination at all. {@see selectableForDelivery()}
 * is the single place that combines them, so no caller has to remember both.
 */
#[Fillable([
    'source_city_id',
    'name',
    'delivery_price_lyd',
    'is_region_required',
    'is_active',
    'fulfilment_kind',
    'darb_branch',
])]
class DeliveryCity extends Model
{
    /**
     * The source city ids that are fulfilment modes rather than places.
     *
     * A list of ids, and emphatically not a match on the name. «إستلام مكتب» is
     * exactly the kind of label that gets re-spelled, and classifying rows by
     * reading them would reclassify this one the first time someone adds a
     * space. Changing this list and re-importing reclassifies the rows it names;
     * nothing else in the import touches the kind.
     *
     * @var list<int>
     */
    public const OFFICE_PICKUP_SOURCE_CITY_IDS = [1];

    /**
     * @return HasMany<DeliveryRegion, $this>
     */
    public function regions(): HasMany
    {
        return $this->hasMany(DeliveryRegion::class, 'city_id');
    }

    /**
     * Orders whose destination is this city.
     *
     * The reference, not the snapshot. An order that named this city under an
     * older spelling still belongs to it — the `city_name` it carries is what it
     * was called then, and is nobody's foreign key.
     *
     * @return HasMany<DeliveryOrder, $this>
     */
    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'city_id');
    }

    /**
     * The cities an employee may choose for a delivery, and only those.
     *
     * Both conditions, always together. Dropping the kind would put office
     * pickup in the destination list; dropping the active flag would resurrect
     * withdrawn cities. Callers get one scope so neither can be forgotten in
     * one place and remembered in another.
     *
     * Says nothing about price. The four unpriced cities are perfectly real
     * destinations and stay selectable — what may not happen is an order being
     * treated as priced when it has no price, and that is a rule about
     * assignment, not about what the list contains (PLAN §4.3).
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    public function selectableForDelivery(Builder $query): void
    {
        $query->where('fulfilment_kind', FulfilmentKind::Delivery)->where('is_active', true);
    }

    /**
     * Whether this city has a decided price.
     *
     * Exists so no caller writes `=== 0.0` or a truthiness test against the
     * column and silently folds the four unpriced cities in with «إستلام مكتب»,
     * whose `0.00` is a decision someone made.
     */
    public function hasDecidedPrice(): bool
    {
        return $this->delivery_price_lyd !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Matches the column, so a price read back is the price written. The
            // cast returns null untouched, which is what keeps "no price
            // decided" distinct from `0.00`.
            'delivery_price_lyd' => 'decimal:2',
            'is_region_required' => 'boolean',
            'is_active' => 'boolean',
            'fulfilment_kind' => FulfilmentKind::class,
        ];
    }
}
