<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fifth inbound channel: where an order stands in Masar's tour execution
 * (CONTRACT §13.29 — D31, draft; §13.12).
 *
 * Ten columns Masar owns and one this company owns, and the pairing is the
 * whole mechanism. Masar states that an order was admitted to a tour, that the
 * tour began, or that the participation ended. Mini Delivery states which
 * assignment each of those statements was made about. Neither writes the
 * other's column, and the projection reads both.
 *
 * **Two independent guards, and neither is redundant.** A participation fact is
 * operative only while *both* hold: the version Masar built it on has not been
 * overtaken by a local reassignment, and the courier Masar named is the one this
 * company currently has the order assigned to. Each catches what the other
 * cannot — the version fence is the only thing that stops an order reassigned
 * A → B → A from reviving a finished tour, since the courier matches again;
 * and the courier check is the only thing that stops an order from reading as
 * being delivered by a courier this database cannot name, or by one it is not
 * even assigned to.
 *
 * **Why the assignment fence exists.** Masar's statement is about the courier who
 * held the order when the statement was made. Reassignment is this company's
 * act, and the only one that ends a membership at the source (§3.18) — but the
 * `ended` announcing it can be delayed, refused, or lost for good, and even
 * when it is lost the statement must stop being operative. Judging that by
 * comparing courier identity does not work: an order reassigned A then B then A
 * matches again, and a participation fact from A's finished tour would come
 * back to life. So the fence is a number that only ever rises —
 * `assignment_order_version`, the `order_version` of the last effective
 * assignment — and a participation fact is operative only while the version
 * Masar built it on is at least that high. Once overtaken it can never become
 * operative again without a *new* announcement built on the newer assignment,
 * which is to say without a real new tour. That is a structural impossibility
 * rather than an unlikely race.
 *
 * `base_order_version` is the same device §13.8.4 gave the data channel — "a
 * precondition, not an ordering key" — and it is legitimate to compare against
 * a local number for the reason §13.12 forbids comparing the others:
 * `order_version` is *one* sequence, this company's own, and both ends hold
 * values from it. No two counters are mixed.
 *
 * **No backfill, deliberately.** Every existing row gets
 * `masar_participation = 'none'` and `assignment_order_version = 0`, and both
 * are the truth rather than a placeholder: Masar has said nothing about these
 * orders, and no assignment of theirs has been observed by this column. A `0`
 * fence is permissive, and permissive is the safe direction here — it can only
 * ever let a *genuinely new* announcement take effect, and there is no stale
 * participation fact on these rows for it to revive. The first assignment after
 * this migration sets the fence properly.
 *
 * Every instant is DATETIME, matching the rest of this schema, and every write
 * is UTC.
 */
return new class extends Migration
{
    /** §13.29 — the three states that travel, plus this system's own initial one. */
    private const PARTICIPATION = ['none', 'scheduled', 'active', 'ended'];

    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            // ---- Masar's, written only by MasarTourParticipationWriter ----

            $table->enum('masar_participation', self::PARTICIPATION)
                ->default('none')
                ->after('masar_status_version');

            // The high-water mark of this channel, and of this channel alone.
            // Independent of `masar_status_version`, `masar_data_version` and
            // `masar_location_version` (§13.12). `0` means Masar has never
            // spoken about participation.
            $table->unsignedBigInteger('masar_participation_version')
                ->default(0)
                ->after('masar_participation');

            // The `order_version` Masar had applied when it made the statement.
            // Nullable because Masar sends null for an order it holds without a
            // mapping, and because a null fence must fail closed rather than
            // compare as zero.
            $table->unsignedBigInteger('masar_participation_base_order_version')
                ->nullable()
                ->after('masar_participation_version');

            // `occurred_at` of the applied event — when Masar says the
            // participation changed. Not a clock reading taken here.
            $table->dateTime('masar_participation_changed_at')
                ->nullable()
                ->after('masar_participation_base_order_version');

            // History across transitions, and the reason these two are separate
            // columns rather than one: `masar_participation_changed_at` is
            // overwritten by every transition, so without them an `ended` would
            // erase when the participation had begun. They accumulate and are
            // never cleared.
            //
            // **Named for the participation, not for the tour, and the names are
            // load-bearing.** Both hold the applied event's `occurred_at` —
            // when Masar announced the transition — and D31 carries no instant
            // for `delivery_tours.started_at` itself. In the "depart now" path
            // those differ by the seconds between `POST /tours` and the
            // bootstrap that seals membership, so a column called
            // `masar_tour_started_at` would quietly invite a reader to treat an
            // announcement instant as the moment a courier drove off. See the
            // contract note in the prompt 3 report.
            $table->dateTime('masar_participation_started_at')->nullable()->after('masar_participation_changed_at');
            $table->dateTime('masar_participation_ended_at')->nullable()->after('masar_participation_started_at');

            // Display and audit only. Deliberately absent from the projection:
            // a scheduled departure is an intention, and the passage of its
            // hour is not evidence that anyone set off.
            $table->dateTime('masar_tour_departure_at')->nullable()->after('masar_participation_ended_at');
            $table->string('masar_tour_reference', 64)->nullable()->after('masar_tour_departure_at');

            // `data.external_courier_id` exactly as it arrived, kept whether or
            // not it resolves. Without it an unmapped courier's identity is
            // discarded at the edge, and nothing afterwards can say whom Masar
            // named — the same reason the event log keeps the version of an
            // event it refused. It is also what makes the repair below possible
            // without asking Masar to speak again.
            $table->string('masar_tour_started_courier_uid', 128)
                ->nullable()
                ->after('masar_tour_reference');

            // The same courier resolved to a local row, and left null when the
            // uid names none — an identity is never invented for an unmapped
            // courier. **Read by the projection**, which will not show an order
            // as being delivered unless the courier Masar says began the
            // participation is the one this company currently has it assigned
            // to. Once the mapping is repaired, re-resolving the uid above
            // fills this in without a new event and without touching the
            // participation state, its version, or idempotency.
            $table->foreignId('masar_tour_started_representative_id')
                ->nullable()
                ->after('masar_tour_started_courier_uid')
                ->constrained('representatives')
                ->restrictOnDelete();

            // ---- Mini Delivery's, written only by DeliveryOrderLifecycleService ----

            $table->unsignedBigInteger('assignment_order_version')
                ->default(0)
                ->after('masar_tour_started_representative_id');

            // The projection filters and sorts on participation, and pairs it
            // with the fence on every read of `in_progress`.
            $table->index(
                ['masar_participation', 'masar_participation_base_order_version'],
                'delivery_order_participation_index',
            );
        });
    }

    /**
     * Dropping these loses the ordering guard for every order Masar has already
     * announced participation for — the same hazard, for the same reason, that
     * `masar_status_version`'s rollback refuses. Re-adding them would restart
     * every high-water mark at zero, and the next stale retry would be applied
     * as though it were new: an order whose tour closed yesterday would show as
     * being delivered today, silently.
     *
     * So the rollback refuses while any order carries a participation version,
     * and is free before the first real event arrives. `assignment_order_version`
     * goes with them under the same condition, because it is only load-bearing
     * while a participation fact exists for it to fence.
     */
    public function down(): void
    {
        if (DB::table('delivery_orders')->where('masar_participation_version', '>', 0)->exists()) {
            throw new RuntimeException(
                'Cannot remove the Masar participation columns while applied participation versions exist: '
                .'the high-water marks and the assignment fence cannot be reconstructed, and dropping them '
                .'would let a stale announcement revive a finished tour.',
            );
        }

        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->dropIndex('delivery_order_participation_index');
            $table->dropConstrainedForeignId('masar_tour_started_representative_id');
            $table->dropColumn([
                'masar_participation',
                'masar_participation_version',
                'masar_participation_base_order_version',
                'masar_participation_changed_at',
                'masar_participation_started_at',
                'masar_participation_ended_at',
                'masar_tour_departure_at',
                'masar_tour_reference',
                'masar_tour_started_courier_uid',
                'assignment_order_version',
            ]);
        });
    }
};
