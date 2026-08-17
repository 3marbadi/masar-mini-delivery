<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['route_id', 'delivery_order_id', 'stop_number', 'expected_arrival'])]
class RouteStop extends Model
{
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    protected function casts(): array
    {
        return [
            'stop_number' => 'integer',
            'expected_arrival' => 'datetime',
        ];
    }
}
