<?php

namespace App\Models;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'customer_id',
    'representative_id',
    'value',
    'location_link',
    'latitude',
    'longitude',
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
            'status' => DeliveryOrderStatus::class,
            'result' => DeliveryOrderResult::class,
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
