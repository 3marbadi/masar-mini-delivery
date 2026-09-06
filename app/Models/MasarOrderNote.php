<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note a Masar courier wrote on one of our orders (CONTRACT §13.16.7).
 *
 * Immutable once written, mirroring the source: Masar's own `notes` is never
 * edited and never deleted (§13.5), and there is no `order.note.updated` event
 * to carry a change even if it were. So there is no `updated_at`, no soft
 * delete, and nothing on this class that rewrites a stored row.
 *
 * Not `delivery_orders`' own idea of a note, and the two are never read for one
 * another (§13.16.4). What this system sends outbound as `order.notes` is its
 * own remark on its own order — replaced wholesale, carrying no author and no
 * instant. This table is a courier's field log: many rows per order, each with a
 * writer and a moment, none of them ours to edit.
 *
 * `masar_note_id` is an identity, never a version (§13.16.2). Nothing compares
 * two of them to decide which is newer, and nothing keeps a high-water mark of
 * them: two notes are two facts, and treating the smaller id as stale would
 * discard one whose retry happened to arrive late.
 */
#[Fillable([
    'delivery_order_id', 'masar_note_id', 'content',
    'masar_representative_id', 'occurred_at', 'masar_event_id', 'created_at',
])]
class MasarOrderNote extends Model
{
    /** No updated_at: the row is written once. `created_at` is set explicitly by its writer. */
    public $timestamps = false;

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    protected function casts(): array
    {
        return [
            'delivery_order_id' => 'integer',
            'masar_note_id' => 'integer',
            'masar_representative_id' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
