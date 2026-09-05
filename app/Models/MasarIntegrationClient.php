<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Masar, as a caller of Mini Delivery (CONTRACT §3.21.2).
 *
 * The counterpart of Masar's own `IntegrationClient`, which represents this
 * system as a caller of that one. Two principals, two directions, and neither
 * grants anything in the other: a client here can announce order statuses and
 * nothing else — it is not a user, has no panel session, and owns no order.
 */
#[Fillable(['name', 'client_id', 'client_secret_hash', 'status'])]
class MasarIntegrationClient extends Model
{
    public function tokens(): HasMany
    {
        return $this->hasMany(MasarIntegrationToken::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
