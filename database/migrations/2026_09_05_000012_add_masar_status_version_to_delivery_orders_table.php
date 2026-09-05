<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The high-water mark of what Masar has told us (CONTRACT §3.21.11).
 *
 * The receiving half of Masar's `orders.delivery_status_version`. Without it the
 * receiver can tell that two announcements are different — `event_id` does that
 * — but not which of them is newer, so a late arrival is applied over something
 * more recent and the mirror silently reverts to a state Masar has withdrawn.
 *
 * Its meaning is narrow and worth stating exactly: the last Masar-owned
 * delivery-status version *successfully applied to this order*. Not the highest
 * seen, not the last received — applied. A version that was rejected, or ignored
 * as stale, leaves it untouched, because it names what the two columns beside it
 * currently reflect.
 *
 * `0` means Masar has never announced anything about this order, which is the
 * state of every row that exists today, so the default is the truth rather than
 * a placeholder and the column is not nullable.
 *
 * BIGINT UNSIGNED, matching what Masar sends. Neither end should be the narrower
 * one: a receiver that overflowed before the sender did would start judging
 * genuinely newer events as stale, and it would do so silently, since a stale
 * event is a successful outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('masar_status_version')
                ->default(0)
                ->after('status_reason');
        });
    }

    /**
     * Dropping this loses the ordering guard for every order Masar has already
     * announced. Re-adding it would restart every high-water mark at zero, and
     * the next stale retry to arrive would be applied as though it were new —
     * silently reverting live state. So the rollback refuses while any order
     * carries a version.
     */
    public function down(): void
    {
        if (DB::table('delivery_orders')->where('masar_status_version', '>', 0)->exists()) {
            throw new RuntimeException('Cannot remove delivery_orders.masar_status_version while applied Masar status versions exist.');
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn('masar_status_version');
        });
    }
};
