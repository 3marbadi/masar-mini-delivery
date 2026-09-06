<?php

namespace App\Models;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\LocationValidationStatus;
use App\Enums\ReadinessStatus;
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
    'location_completed_at',
    'status',
    'result',
    'completed_at',
    'cancelled_at',
])]
class DeliveryOrder extends Model
{
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
            'location_completed_at' => 'datetime',
            'status' => DeliveryOrderStatus::class,
            'result' => DeliveryOrderResult::class,
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
