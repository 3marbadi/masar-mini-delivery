<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the two new channels claimed (CONTRACT §13.16.3, §13.17.3, v5.5).
 *
 * The event log already keeps `status_version` and `data_version` for one
 * reason: «which version did they send» is the first question when a mirror and
 * its source disagree, and it is unanswerable from the order row, which only
 * holds the version that won. The two channels added in v5.5 need the same
 * treatment, and each needs a column of its own for the reason §13.12 gives —
 * four independent sequences cannot share a slot without making every recorded
 * number ambiguous.
 *
 * `location_version` is the location channel's ordering key, kept even when the
 * event was ignored or refused. `LOCATION_BASE_VERSION_CONFLICT` in particular
 * is exactly the case someone comes here to investigate, and «what did they
 * claim, and what did they think ours was» needs both this and the existing
 * `base_order_version` — which the location channel reuses as-is, because it
 * means precisely what it means on the data channel: the version of *our*
 * outbound sequence the announcement was built on.
 *
 * `masar_note_id` is not a version and is not treated as one (§13.16.2). It is
 * the note's identity at Masar, recorded so a replayed or conflicting
 * announcement can be traced to the note it was about without reading the stored
 * payload back out.
 *
 * All nullable, as `status_version` and `data_version` are: null says «this
 * channel did not speak», which is the truth for three of the four on any given
 * row, and a zero would claim something false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masar_integration_events', function (Blueprint $table) {
            $table->unsignedBigInteger('location_version')->nullable()->after('base_order_version');
            $table->unsignedBigInteger('masar_note_id')->nullable()->after('location_version');
        });
    }

    /**
     * Refused while either channel has been recorded.
     *
     * Dropping these loses the evidence that makes a replayed announcement
     * answerable — not the identity itself, which `event_id` holds, but the
     * record of what was claimed and what was applied. The table's own rollback
     * and the data channel's columns take the same position for the same reason.
     */
    public function down(): void
    {
        $recorded = DB::table('masar_integration_events')
            ->whereNotNull('location_version')
            ->orWhereNotNull('masar_note_id')
            ->exists();

        if ($recorded) {
            throw new RuntimeException('Cannot drop the Masar note and location event columns while inbound notes or locations have been recorded.');
        }

        Schema::table('masar_integration_events', function (Blueprint $table) {
            $table->dropColumn(['location_version', 'masar_note_id']);
        });
    }
};
