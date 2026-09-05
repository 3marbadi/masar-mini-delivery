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
    'representative_id',
    'location_id',
    'tour_id',
    'value',
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
    'location_completed_at',
    'status',
    'result',
    'completed_at',
    'cancelled_at',
])]
class DeliveryOrder extends Model
{
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
