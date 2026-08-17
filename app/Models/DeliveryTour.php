<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['representative_id', 'start_location_id', 'departure_time'])]
class DeliveryTour extends Model
{
    public function representative(): BelongsTo
    {
        return $this->belongsTo(Representative::class);
    }

    public function startLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'start_location_id');
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'tour_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class, 'tour_id');
    }

    protected function casts(): array
    {
        return ['departure_time' => 'datetime'];
    }
}
