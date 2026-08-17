<?php

namespace App\Models;

use App\Enums\OrderChangeType;
use App\Enums\RouteImpactLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['delivery_order_id', 'change_type', 'affects_route', 'impact_level', 'occurred_at', 'seen_at'])]
class OrderChange extends Model
{
    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function proposedRoute(): HasOne
    {
        return $this->hasOne(Route::class);
    }

    protected function casts(): array
    {
        return [
            'change_type' => OrderChangeType::class,
            'affects_route' => 'boolean',
            'impact_level' => RouteImpactLevel::class,
            'occurred_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }
}
