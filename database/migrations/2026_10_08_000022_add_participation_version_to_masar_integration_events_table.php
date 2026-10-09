<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The participation channel's own number in the shared event log
 * (CONTRACT §13.29 — D31, draft; §13.12).
 *
 * The fifth of its kind on this table, added the same way the third and fourth
 * were: `status_version`, then `data_version` and `base_order_version`, then
 * `location_version` and `masar_note_id`, and now this. One nullable column per
 * channel rather than one shared "version", because §13.12 is categorical that
 * no two of these sequences may be compared — and a single column holding
 * whichever number arrived would invite exactly that comparison, in a query
 * nobody reviewed, months from now.
 *
 * Nullable, and null carries meaning on every row written by the other four
 * channels: that event said nothing about participation. A `0` would claim it
 * had.
 *
 * `base_order_version` is **not** added again. The column already exists for
 * the data channel (§13.8.4) and this channel carries a value with the same
 * meaning from the same sequence, so it is reused rather than duplicated — the
 * alternative would be two columns holding this company's `order_version` and
 * no rule saying which to read.
 *
 * The rollback is free. Dropping this loses an audit number and no state: the
 * order's own high-water mark lives on `delivery_orders`, and the idempotency
 * identity is `(client, event_id)`, which is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masar_integration_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('participation_version')
                ->nullable()
                ->after('masar_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('masar_integration_events', function (Blueprint $table): void {
            $table->dropColumn('participation_version');
        });
    }
};
