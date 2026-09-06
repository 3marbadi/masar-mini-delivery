<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The location channel's high-water mark (CONTRACT §13.17.3, v5.5).
 *
 * The exact counterpart of `masar_status_version` and `masar_data_version`
 * beside it, and deliberately **not** either of them. §13.12 forbids one column
 * carrying two meanings: Masar's three sequences are independent by contract, so
 * an order can sit at status version 4, data version 1 and location version 3 at
 * once, and a shared column would make "version 3" ambiguous the moment two
 * channels had spoken. Worse, the staleness rule would then compare a location
 * against a status and discard one of them.
 *
 * Zero means «Masar has never announced a location for this order», which is
 * true of every row that exists when this runs — the column is new and no
 * location event has ever been accepted.
 *
 * Nothing is added for the coordinates themselves. `latitude`, `longitude` and
 * `location_completed_at` already exist on this table and are exactly where a
 * completed location belongs; the location channel writes those columns and this
 * counter, and nothing else (§13.17.1). What was missing was never a destination
 * — it was the receiver and the high-water mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('masar_location_version')
                ->default(0)
                ->after('masar_data_version');
        });
    }

    /**
     * Refused once any order has accepted a location from Masar.
     *
     * The column is the only record of how far this channel has got. Dropping it
     * restarts the mark at zero, and the next announcement — whatever version it
     * carries — would be applied as though nothing had been seen, including one
     * that had already been superseded. That is silent divergence in a
     * coordinate a courier drives to, which is the failure the whole channel
     * exists to prevent.
     *
     * The position `masar_status_version` and `masar_data_version` take, for the
     * same reason.
     */
    public function down(): void
    {
        if (Schema::hasColumn('delivery_orders', 'masar_location_version')
            && DB::table('delivery_orders')->where('masar_location_version', '>', 0)->exists()) {
            throw new RuntimeException('Cannot remove delivery_orders.masar_location_version while applied Masar location versions exist.');
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn('masar_location_version');
        });
    }
};
