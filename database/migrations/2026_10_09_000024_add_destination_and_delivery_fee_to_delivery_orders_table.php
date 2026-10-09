<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where an order is going, and what it was charged to go there (PLAN D1 §4.1).
 *
 * Five columns, and they divide cleanly in two: two references into the catalog,
 * and three snapshots the catalog can never reach again.
 *
 * **Everything is nullable, and that is the compatibility guarantee.** Every
 * order already in this table predates the catalog and has no destination to
 * record — not an unknown one, an absent one. There is no backfill and no
 * default, because any value invented here would be indistinguishable from one
 * an employee chose, and the four unpriced cities make `0.00` the most dangerous
 * guess available. Old orders read null, save, update and cancel exactly as
 * before; the D2 form decides what a *new* order must carry, and it decides it
 * in the validation layer rather than here.
 *
 * **Why the names are stored as well as referenced.** `city_id` and `region_id`
 * say which catalog rows were chosen; `city_name` and `region_name` say what
 * those rows were *called* at the moment of choosing. The reference alone would
 * make the order's own history mutable: rename «ضواحي طرابلس» in the catalog and
 * every order ever sent there silently re-describes itself, including the ones
 * already delivered, already invoiced, and already recorded in Masar under the
 * old name. A rename is a correction to the catalog, never a rewrite of what was
 * agreed. The snapshot is written beside the reference when the destination is
 * set, and nothing that edits the catalog touches it afterwards.
 *
 * **`delivery_fee_lyd` is the same argument about money.** It is the city's price
 * as it stood when the order was registered, copied once (PLAN §4.3). The
 * catalog price is a current offer; this is a concluded one. A central price
 * change applies to future orders and to nothing else, so this column is read
 * where a historic amount is needed and the catalog is never consulted for a
 * past order. Null here means the same thing it means in the catalog: *no price
 * was decided*, which is the state the four unpriced cities are in, and it is
 * not a fee of zero.
 *
 * **Deliberately not `value`.** `value` is what the customer's goods are worth
 * and travels to Masar as `amount`; this is what the delivery itself costs and
 * travels as `delivery_cost`. Two separate amounts that happen to be money, and
 * conflating them would silently alter the first.
 *
 * **Nothing existing is touched.** No status, result, `masar_*_version`,
 * assignment fence or outbox column is read, repurposed or rewritten by this
 * migration. These five columns are additive, inert until something writes them,
 * and invisible to every version comparison in the system.
 *
 * **The region/city agreement is not enforced here.** A foreign key proves
 * `region_id` names *a* region; it cannot prove that region belongs to
 * `city_id`, because the constraint has no view of the other column. The schema
 * therefore prepares the relationship and no more, and the rule — a region must
 * belong to its order's city — is enforced in the save path with a test of its
 * own in D2 (PLAN §4.1, §5.2.3). Pretending a foreign key had done it is how
 * that check never gets written.
 */
return new class extends Migration
{
    /**
     * The columns that hold a concluded fact rather than a current one.
     *
     * Checked before any rollback: see {@see down()}.
     */
    private const SNAPSHOTS = ['city_id', 'region_id', 'city_name', 'region_name', 'delivery_fee_lyd'];

    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            // ---- References into the catalog ----

            // `restrictOnDelete` on both: an order's destination is part of its
            // record, and a catalog row an order points at is not deletable. The
            // importer never deletes, and `is_active` is how a destination is
            // withdrawn, so this constraint should never fire — it is here to be
            // the thing that fails loudly if someone reaches for a DELETE.
            $table->foreignId('city_id')
                ->nullable()
                ->after('value')
                ->constrained('delivery_cities')
                ->restrictOnDelete();

            // Null for the 86 cities that do not demand a region, and for every
            // order that predates the catalog. The two are the same absence to
            // the database and different absences to the business; `is_region_required`
            // on the city is what tells them apart.
            $table->foreignId('region_id')
                ->nullable()
                ->after('city_id')
                ->constrained('delivery_regions')
                ->restrictOnDelete();

            // ---- Snapshots, written once with the destination ----

            $table->string('city_name')->nullable()->after('region_id');
            $table->string('region_name')->nullable()->after('city_name');

            // Same precision as the catalog column it is copied from, so a price
            // cannot change by being written down.
            $table->decimal('delivery_fee_lyd', 12, 2)->nullable()->after('region_name');
        });
    }

    /**
     * Refuses while any order carries a destination or a fee.
     *
     * The catalog is reconstructible from the source file; these columns are
     * not. Dropping them would discard what each order was charged and the names
     * it was charged under — the historic amounts PLAN §4.3 exists to protect —
     * and no later import brings them back, because the catalog holds today's
     * prices and has no memory of the ones that were agreed.
     *
     * The check covers all five columns rather than the fee alone: an order with
     * a destination and no price is one of the four unpriced cities, which is a
     * real state and just as unreconstructible.
     */
    public function down(): void
    {
        $carrying = DB::table('delivery_orders')
            ->where(function ($query): void {
                foreach (self::SNAPSHOTS as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->count();

        if ($carrying > 0) {
            throw new RuntimeException(
                "Cannot remove the destination and delivery-fee columns while {$carrying} order(s) carry them: "
                .'the fee and the city and region names are snapshots of what was agreed at registration, '
                .'they cannot be reconstructed from the catalog, and dropping them would rewrite the '
                .'financial history of every order that has one.',
            );
        }

        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('region_id');
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn(['city_name', 'region_name', 'delivery_fee_lyd']);
        });
    }
};
