<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What version a data correction claimed (CONTRACT §13.8.2, §13.8.4).
 *
 * The event log already keeps `status_version` for exactly this reason: «which
 * version did they send» is the first question when a mirror and its source
 * disagree, and it is unanswerable from the order row, which only holds the
 * version that won. A data correction carries two numbers of its own and both
 * are kept here for the same reason.
 *
 * A second column rather than a reuse of `status_version`, because the two
 * sequences are independent (§13.8.2) and a single column would make «version 4»
 * ambiguous the moment both channels had spoken about one order. Nullable on
 * both sides now: a status event has no data version and a data event has no
 * status version, and null says «this channel did not speak» rather than
 * pretending to a zero.
 *
 * `base_order_version` is the correction's precondition, not its ordering key
 * (§13.8.4): the version of *our* `order.updated` sequence that Masar built the
 * correction on. It is kept even when the check refuses, because a
 * `DATA_BASE_VERSION_CONFLICT` is precisely the case someone will come here to
 * investigate, and «what did they think our version was» is the whole question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masar_integration_events', function (Blueprint $table) {
            $table->unsignedBigInteger('data_version')->nullable()->after('status_version');
            $table->unsignedBigInteger('base_order_version')->nullable()->after('data_version');
        });
    }

    /**
     * Refused while any data event has been recorded.
     *
     * Dropping these loses the evidence that makes a replayed correction
     * answerable — not the identity itself, which `event_id` holds, but the
     * record of what was claimed and what was applied. The table's own rollback
     * takes the same position for the same reason.
     */
    public function down(): void
    {
        $recorded = DB::table('masar_integration_events')
            ->whereNotNull('data_version')
            ->orWhereNotNull('base_order_version')
            ->exists();

        if ($recorded) {
            throw new RuntimeException('Cannot drop the Masar data-event version columns while inbound data corrections have been recorded.');
        }

        Schema::table('masar_integration_events', function (Blueprint $table) {
            $table->dropColumn(['data_version', 'base_order_version']);
        });
    }
};
