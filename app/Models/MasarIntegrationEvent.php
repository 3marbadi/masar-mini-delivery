<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event Masar sent, and what became of it (CONTRACT §3.21.6).
 *
 * The mirror of `integration_events` on Masar's side, and it exists for the same
 * reason: a retry must be answered exactly as the original was, and that answer
 * can only come from a durable record of the original. The current status of an
 * order is never read from here — that lives on `delivery_orders` — this table
 * only says which announcements have already been applied.
 *
 * `payload_hash` is what separates a retry from a reuse. The same `event_id`
 * with the same digest replays the saved answer; the same `event_id` with a
 * different digest is a conflict, because one identity cannot have described two
 * different changes.
 */
#[Fillable([
    'masar_integration_client_id', 'request_id', 'event_id', 'event_type',
    'external_order_id', 'delivery_order_id', 'payload_hash', 'status_version',
    'data_version', 'base_order_version',
    'result', 'http_status', 'error_code', 'error_message',
    'received_at', 'processed_at',
])]
class MasarIntegrationEvent extends Model
{
    public function client(): BelongsTo
    {
        return $this->belongsTo(MasarIntegrationClient::class, 'masar_integration_client_id');
    }

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
            'delivery_order_id' => 'integer',
            'status_version' => 'integer',
            // Null on a status event and on a data event respectively, and never
            // coerced to zero: null says «this channel did not speak».
            'data_version' => 'integer',
            'base_order_version' => 'integer',
            'http_status' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
