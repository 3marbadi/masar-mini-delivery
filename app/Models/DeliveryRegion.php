<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A region within a delivery city (PLAN D1 §4.1).
 *
 * Carries no price of its own, and will not acquire one by accident: the source
 * file prices cities, every region in a city shares that city's price, and any
 * future per-region pricing is a separate design rather than a column added here
 * (PLAN §4.3).
 *
 * `region_code` is a label and never an identity. Eleven distinct codes cover
 * 221 regions — `s5` on 60 of them — and 48 regions have none, so two regions
 * sharing a code is the normal case and says nothing about them being related.
 * Identity is `source_region_id` on the way in and `id` on the way out.
 */
#[Fillable([
    'source_region_id',
    'city_id',
    'name',
    'region_code',
    'is_active',
    'darb_branch',
])]
class DeliveryRegion extends Model
{
    /**
     * @return BelongsTo<DeliveryCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'city_id');
    }

    /**
     * @return HasMany<DeliveryOrder, $this>
     */
    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'region_id');
    }

    /**
     * The regions of one city that an employee may choose.
     *
     * Scoped by `city_id` and nothing else — never by `region_code`, which would
     * silently sweep in regions of other cities that happen to share a routing
     * label. The city's own selectability is the city's question; this answers
     * the second one only.
     *
     * `$currentId` re-admits the one region an order already carries, for the
     * same reason the city list does it (D2): an option list that omits the
     * value it is displaying renders empty and saves that emptiness back. The
     * `city_id` filter stays *outside* that widening and is never relaxed, so
     * re-admitting a withdrawn region can never re-admit another city's — which
     * is the mistake that would turn a display fix into a foreign-region write.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    public function selectableForCity(Builder $query, int $cityId, ?int $currentId = null): void
    {
        $query->where('city_id', $cityId)->where(function (Builder $query) use ($currentId): void {
            $query->where('is_active', true);

            if ($currentId !== null) {
                $query->orWhere('id', $currentId);
            }
        });
    }

    /**
     * Whether this region belongs to the given city.
     *
     * The question a foreign key cannot answer — it proves the region exists,
     * not that it is the right one for the city beside it on the order. D2
     * enforces this in the save path; the comparison lives here so both the form
     * and the validator ask it the same way.
     */
    public function belongsToCity(int $cityId): bool
    {
        return (int) $this->city_id === $cityId;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
