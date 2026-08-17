<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['location_link', 'latitude', 'longitude'])]
class Location extends Model
{
    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function startingTours(): HasMany
    {
        return $this->hasMany(DeliveryTour::class, 'start_location_id');
    }

    public function startingRoutes(): HasMany
    {
        return $this->hasMany(Route::class, 'start_location_id');
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
