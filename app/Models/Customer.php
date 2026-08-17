<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'is_active', 'total_orders', 'delivered_orders'])]
class Customer extends Model
{
    /**
     * @return HasMany<DeliveryOrder, $this>
     */
    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'total_orders' => 'integer',
            'delivered_orders' => 'integer',
        ];
    }
}
