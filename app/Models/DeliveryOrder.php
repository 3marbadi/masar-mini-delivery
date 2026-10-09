<?php

namespace App\Models;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\LocationValidationStatus;
use App\Enums\ReadinessStatus;
use App\Enums\TourParticipation;
use App\Models\Concerns\HasIntegrationUid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'customer_id',
    // The order's own recipient (§13.14, D7). Corrected by Masar's couriers on
    // this order alone, and never read from or written to the shared customer
    // profile — see the migration that added them.
    'recipient_name',
    'recipient_phone',
    'recipient_alternate_phone',
    'representative_id',
    'location_id',
    'tour_id',
    'value',
    // The destination the employee chose, and what it cost (PLAN D1 §4.1).
    // `city_name`, `region_name` and `delivery_fee_lyd` are snapshots: written
    // beside the references and never revised by a later edit to the catalog, so
    // renaming a city or repricing it cannot rewrite what a past order says it
    // was, or what it was charged.
    'city_id',
    'region_id',
    'city_name',
    'region_name',
    'delivery_fee_lyd',
    'delivery_payer',
    'location_link',
    'latitude',
    'longitude',
    'location_validation_status',
    'readiness_status',
    'available_from',
    'available_until',
    'confirmed_at',
    'delivery_status',
    'status_reason',
    'masar_status_version',
    'masar_data_version',
    'masar_location_version',
    // The participation channel (§13.29 — D31, draft). Masar's, written only by
    // MasarTourParticipationWriter; listed here so fixtures can construct a
    // state the wire would have produced.
    'masar_participation',
    'masar_participation_version',
    'masar_participation_base_order_version',
    'masar_participation_changed_at',
    'masar_participation_started_at',
    'masar_participation_ended_at',
    'masar_tour_departure_at',
    'masar_tour_reference',
    'masar_tour_started_courier_uid',
    'masar_tour_started_representative_id',
    // Mini Delivery's own fence, written only by DeliveryOrderLifecycleService.
    'assignment_order_version',
    'location_changed_at',
    'location_change_source',
    'location_change_event_id',
    'location_completed_at',
    'status',
    'result',
    'completed_at',
    'cancelled_at',
])]
class DeliveryOrder extends Model
{
    use HasIntegrationUid;

    /**
     * Seed the recipient snapshot at creation (CONTRACT §13.14 — v5.1, D7).
     *
     * The counterpart of the backfill that filled every row already in the
     * table: the backfill answered "what about the past", and this answers "what
     * about the next one". Between the two, every order has a recipient of its
     * own, which is what lets every *read* of a recipient be a read of this
     * order's snapshot and nothing else — no coalesce, no fallback, no chance of
     * one order's screen showing a value a sibling's history explains.
     *
     * Seeding is not the fallback D7 forbids, and the difference is the moment it
     * happens. This copies once, at insert, and the order owns the value from
     * then on: a later change to the shared profile does not reach it, and a
     * courier's correction to it does not reach the profile. A read-time
     * coalesce would re-establish exactly the coupling that copy removes.
     *
     * It runs on the model rather than in the one admin page that creates
     * orders, because the guarantee has to hold for *every* creation path, and
     * a page is a path someone will one day add a second of.
     *
     * Explicit values win: a caller that already knows the recipient — an
     * importer, a fixture reproducing a corrected order — is not overwritten.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if ($order->recipient_name !== null && $order->recipient_phone !== null) {
                return;
            }

            $customer = Customer::query()->find($order->customer_id);

            if ($customer === null) {
                // The foreign key will refuse this insert in a moment and say so
                // far better than a guess here would.
                return;
            }

            $order->recipient_name ??= $customer->name;
            $order->recipient_phone ??= $customer->phone;
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Representative, $this>
     */
    public function representative(): BelongsTo
    {
        return $this->belongsTo(Representative::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The catalog city this order is going to (PLAN D1 §4.1).
     *
     * Null on every order placed before the catalog existed, and on any order
     * whose destination has not been set — which is an absence rather than an
     * unknown, and the reason nothing here was backfilled.
     *
     * Emphatically not the same information as {@see location()}: that is where
     * the parcel is, in coordinates, and it is what routing reads. This is the
     * administrative destination the employee chose and what the price was based
     * on. Neither substitutes for the other, and choosing a city never moves a
     * pin.
     *
     * @return BelongsTo<DeliveryCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'city_id');
    }

    /**
     * The region within that city, where the city demands one.
     *
     * Null for the 86 cities that do not, and for historic orders. That the
     * region belongs to `city_id` is not something this relation can promise —
     * a foreign key cannot see the column beside it — and it is enforced in the
     * save path in D2 (PLAN §5.2.3).
     *
     * @return BelongsTo<DeliveryRegion, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(DeliveryRegion::class, 'region_id');
    }

    /**
     * The courier Masar named in the participation statement, resolved locally.
     *
     * Emphatically **not** `representative()`. That one is who this company has
     * the order assigned to; this one is who Masar said began the participation,
     * and the projection refuses to show an order as being delivered unless the
     * two are the same. Keeping them as separate relations is what lets the
     * interface say *which* of the two is which when they disagree.
     *
     * Null whenever `masar_tour_started_courier_uid` named no row here — an
     * identity is never invented for an unmapped courier.
     *
     * @return BelongsTo<Representative, $this>
     */
    public function masarTourStartedRepresentative(): BelongsTo
    {
        return $this->belongsTo(Representative::class, 'masar_tour_started_representative_id');
    }

    public function deliveryTour(): BelongsTo
    {
        return $this->belongsTo(DeliveryTour::class, 'tour_id');
    }

    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }

    public function orderChanges(): HasMany
    {
        return $this->hasMany(OrderChange::class);
    }

    /**
     * @return HasOne<OrderIntegrationState, $this>
     */
    public function integrationState(): HasOne
    {
        return $this->hasOne(OrderIntegrationState::class);
    }

    /**
     * @return HasMany<IntegrationOutbox, $this>
     */
    public function integrationOutboxEvents(): HasMany
    {
        return $this->hasMany(IntegrationOutbox::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            // What this order was charged for delivery, fixed at registration
            // (PLAN §4.3). Null is not free delivery: it is the state of the
            // four cities the source file prices at nothing, and the cast
            // returns it untouched so the two never merge.
            'delivery_fee_lyd' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'location_validation_status' => LocationValidationStatus::class,
            'readiness_status' => ReadinessStatus::class,
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivery_status' => DeliveryStatus::class,
            // The last Masar-owned delivery-status version applied to this
            // order (CONTRACT §3.21.11). Written only by the Masar receiver.
            'masar_status_version' => 'integer',
            // The last Masar-owned location version applied to this order
            // (CONTRACT §13.17.3). Written only by the Masar location receiver,
            // and independent of the two versions beside it (§13.12).
            'masar_location_version' => 'integer',
            // Where this order stands in Masar's tour execution (§13.29 — D31,
            // draft). A fourth independent domain: its version orders this
            // channel and nothing else, and is never compared with the three
            // above (§13.12).
            'masar_participation' => TourParticipation::class,
            'masar_participation_version' => 'integer',
            // Nullable on purpose, and the cast preserves that: Laravel's
            // primitive casts return null untouched, so the column reads null
            // rather than `0`. That matters — the projection's fence fails
            // closed on null, and a `0` would compare as satisfied.
            'masar_participation_base_order_version' => 'integer',
            'masar_participation_changed_at' => 'datetime',
            // Accumulating history. Neither is cleared by a later transition —
            // `masar_participation_changed_at` carries the current one.
            //
            // Both hold the announcing event's `occurred_at`, which is what
            // their names say: when Masar announced the transition. Neither is
            // `delivery_tours.started_at`, and the operational projection reads
            // neither.
            'masar_participation_started_at' => 'datetime',
            'masar_participation_ended_at' => 'datetime',
            // Display and audit only. Deliberately read by nothing in the
            // operational projection: a scheduled departure is an intention,
            // and its hour passing is not evidence that a courier set off.
            'masar_tour_departure_at' => 'datetime',
            // Mini Delivery's own: the `order_version` of the last effective
            // assignment. The fence that stops a superseded participation fact
            // from reviving when an order returns to an earlier courier.
            'assignment_order_version' => 'integer',
            // The arbiter of which side's location change happened later
            // (CONTRACT §13.17.5, D13). Distinct from `location_completed_at`
            // beside it: that is Masar's field fact and moves only for a
            // courier's completion, while this moves for a location change from
            // either origin — and from nothing else.
            'location_changed_at' => 'datetime',
            'location_completed_at' => 'datetime',
            'status' => DeliveryOrderStatus::class,
            'result' => DeliveryOrderResult::class,
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
