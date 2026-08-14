<?php

namespace App\Models;

use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'delivery_order_id',
    'event_type',
    'order_version',
    'payload',
    'status',
    'attempts',
    'last_attempt_at',
    'sent_at',
    'last_error',
])]
class IntegrationOutbox extends Model
{
    protected $table = 'integration_outbox';

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
            'event_type' => IntegrationEventType::class,
            'order_version' => 'integer',
            'payload' => 'array',
            'status' => IntegrationOutboxStatus::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
