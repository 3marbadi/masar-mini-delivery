<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'delivery_order_id',
    'current_version',
    'assigned_transmitted_at',
])]
class OrderIntegrationState extends Model
{
    /**
     * @return BelongsTo<DeliveryOrder, $this>
     */
    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
            'assigned_transmitted_at' => 'datetime',
        ];
    }
}
