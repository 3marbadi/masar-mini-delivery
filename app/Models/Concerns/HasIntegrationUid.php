<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Mints the durable identity this entity shows across the Masar boundary.
 *
 * On the model rather than on a form, a service or a Filament page, because the
 * guarantee has to hold for every way a row can come into existence — the panel,
 * a factory, a seeder, Tinker, a console command, a service writing directly.
 * A row without this value would be a row the integration cannot name, and the
 * only place that covers all of those callers at once is the creating event.
 *
 * Generated here and never accepted from outside: the column is absent from
 * every `#[Fillable]` list, so no request payload can set it, and nothing
 * regenerates it afterwards — `creating` fires once in a row's life. That is
 * what makes it immutable in practice. Renaming a courier, correcting a phone
 * number or deactivating an account all leave it exactly as it was, which is
 * the whole point: Masar resolves its mapping by this value, and an identity
 * that moved when a name was corrected would be no identity at all.
 *
 * The `=== ''` guard lets a caller that genuinely owns a value — a test
 * reproducing a rebuilt database, a fixture pinning an expectation — supply it,
 * while an ordinary create still gets one for free.
 */
trait HasIntegrationUid
{
    protected static function bootHasIntegrationUid(): void
    {
        static::creating(function (self $model): void {
            if ((string) ($model->integration_uid ?? '') === '') {
                $model->integration_uid = (string) Str::uuid7();
            }
        });
    }
}
