<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One short-lived bearer token issued to Masar (CONTRACT §3.21.2).
 *
 * Only the hash is here. The plaintext exists in the response that issued it and
 * nowhere else, so this table cannot be read for a working credential.
 *
 * Expiry is a stored instant rather than a derived one: the lifetime is a
 * configuration value that may change, and a token already in Masar's hands
 * must keep the life it was granted rather than acquiring whatever the current
 * setting says.
 */
#[Fillable(['masar_integration_client_id', 'token_hash', 'expires_at', 'last_used_at'])]
class MasarIntegrationToken extends Model
{
    public function client(): BelongsTo
    {
        return $this->belongsTo(MasarIntegrationClient::class, 'masar_integration_client_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
