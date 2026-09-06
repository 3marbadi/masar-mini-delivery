<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stamp that decides which location change happened later (CONTRACT
 * §13.17.5, D13, v5.5).
 *
 * A migration of its own rather than an edit to `000017`, which added
 * `masar_location_version` earlier in this same unreleased batch. The trace is
 * worth more than the tidiness: read in order, the batch shows the base-version
 * rule arrive and then be withdrawn, which is what actually happened.
 *
 * **Why a stamp at all.** The location is shared-write — Masar's courier writes
 * it and this system writes it — and D13 settles the collision as *last business
 * write wins*. Not last arrival: a delayed event carrying an older change must
 * never overwrite a newer one, however often it is retried. That rules out both
 * counters. `masar_location_version` is Masar's sequence and
 * `order_integration_states.current_version` is ours, and two sequences minted
 * by two independent systems are not comparable — "version 3 here" says nothing
 * against "version 7 there". What compares is when each change actually
 * happened.
 *
 * **Why not `updated_at`.** §13.17.5 requires the stamp to be isolated: it moves
 * for a location change and for nothing else. `updated_at` moves for every write
 * to the row — a status applied from Masar, a corrected recipient, an amount —
 * so an unrelated edit would make a stale location look newer than a good one
 * and win with it.
 *
 * **Why not `location_completed_at`.** That is Masar's field fact: when a
 * courier completed the location. Nothing in this system's own edit path writes
 * it, so a local change would leave it untouched and an old Masar completion
 * would outrank every local change for ever.
 *
 * **The backfill** seeds from `masar_location_version` where a Masar location
 * has already been applied, using `location_completed_at` as the instant, since
 * that is the instant Masar sent with it. Orders that never took a Masar
 * location get no stamp, and a null stamp means "no accepted change", so the
 * next change of either origin wins outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            // The arbiter (§13.17.5). Nullable: an order whose location neither
            // side has changed holds no stamp, and the first change of either
            // origin wins against nothing.
            $table->dateTime('location_changed_at')->nullable()->after('masar_location_version');

            // Which side made the accepted change. Two literals, shared verbatim
            // with Masar: `masar` and `delivery_company`.
            $table->string('location_change_source', 32)->nullable()->after('location_changed_at');

            // The identity of the accepted change, and the last tie-break term.
            // Decisive by construction: two distinct changes never share one.
            $table->string('location_change_event_id', 64)->nullable()->after('location_change_source');
        });

        // §13.17.5 — seed from what Masar has already applied here. Only rows
        // that actually took a Masar location get a stamp; the rest are left
        // null, which is the honest reading of "nothing has claimed this yet".
        DB::statement(<<<'SQL'
UPDATE delivery_orders
   SET location_changed_at = location_completed_at,
       location_change_source = 'masar',
       location_change_event_id = CONCAT('backfill:', id)
 WHERE masar_location_version > 0
   AND location_completed_at IS NOT NULL
SQL);
    }

    /**
     * Fail-closed while any order holds a stamp that is not the backfill's.
     *
     * A real stamp is the only record of which side currently owns an order's
     * location. Dropping it does not restore an earlier behaviour — it removes
     * the arbiter, and the next event of either origin would then win by
     * arrival, which is exactly what D13 forbids.
     */
    public function down(): void
    {
        $accepted = DB::table('delivery_orders')
            ->whereNotNull('location_changed_at')
            ->where(function ($query) {
                $query->where('location_change_source', '<>', 'masar')
                    ->orWhere('location_change_event_id', 'not like', 'backfill:%');
            })
            ->exists();

        if ($accepted) {
            throw new RuntimeException('Cannot drop the location change stamp while accepted location changes are recorded.');
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['location_changed_at', 'location_change_source', 'location_change_event_id']);
        });
    }
};
