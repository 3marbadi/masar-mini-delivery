<?php

namespace App\Models;

use App\Enums\RouteDecision;
use App\Enums\RouteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tour_id', 'start_location_id', 'order_change_id', 'status', 'ordering_reason', 'decision', 'rejection_reason', 'built_at'])]
class Route extends Model
{
    public function deliveryTour(): BelongsTo
    {
        return $this->belongsTo(DeliveryTour::class, 'tour_id');
    }

    public function startLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'start_location_id');
    }

    public function triggeringChange(): BelongsTo
    {
        return $this->belongsTo(OrderChange::class, 'order_change_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }

    protected function casts(): array
    {
        return [
            'status' => RouteStatus::class,
            'decision' => RouteDecision::class,
            'built_at' => 'datetime',
        ];
    }
}
