<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The receiving side of Masar's data channel (CONTRACT §13.8, §13.14 — v5.1, D7).
 *
 * Masar's couriers correct what an order says — who receives it, its two
 * numbers, its amount, and who bears the delivery fee — and announce each
 * correction as `order.data.updated`. Nothing here could take those values
 * until now, and where they land is the whole of D7 restated from this side.
 *
 * **The three recipient columns are on the order, not on the customer, and that
 * is deliberate.** `customers` is one row behind every order a person ever
 * placed: `customers.phone` is even UNIQUE here, so two orders corrected to the
 * same number would collide, and one order corrected would rewrite what every
 * sibling order displays. Masar addresses its correction to one order — one
 * `order_id` in the envelope, a per-order `data_version`, a `base_order_version`
 * that is this order's version — and the only honest place to apply it is the
 * order. The shared profile is left exactly as it is, maintained by this
 * company's own customer screens and by nothing on this channel.
 *
 * All three are nullable. Nothing in this codebase creates a `delivery_orders`
 * row — orders arrive from the operational side and the outbound integration
 * only reads them — so there is no writer that could guarantee a snapshot on a
 * row created tomorrow, and a NOT NULL column would be a promise made by nobody.
 * The backfill below fills every row that exists, and
 * `IntegrationEventGenerationService` falls back to the customer for a row that
 * somehow has none, so a null is a state to describe rather than one to forbid.
 *
 * `recipient_alternate_phone` has no source at all here — this company's
 * `customers` table has no second number — so it starts null on every row and
 * only Masar's own correction can ever set it. That is truthful: an alternate
 * number nobody recorded is absent, not empty.
 *
 * `delivery_payer` is added for the same reason and with the same reluctance:
 * Mini Delivery has no fee-bearer concept of its own. Accepting Masar's
 * correction and silently discarding this one path would be worse — the sender
 * would be answered `processed` about something that never landed — so the
 * column exists to hold what is announced, defaulting to NULL for «never
 * stated» rather than to a guessed side.
 *
 * `masar_data_version` is the data channel's high-water mark, the exact
 * counterpart of `masar_status_version` beside it and deliberately **not** the
 * same column. §13.8.2 makes the two sequences independent: a status
 * announcement and a data correction are separate acts with separate orderings,
 * and sharing one counter would make each channel judge the other's events
 * stale. `0` means Masar has never corrected this order, which is the truth for
 * every row today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('recipient_name', 255)->nullable()->after('customer_id');
            $table->string('recipient_phone', 255)->nullable()->after('recipient_name');
            $table->string('recipient_alternate_phone', 255)->nullable()->after('recipient_phone');

            // Nullable and with no default: «no side has been stated», which is
            // the truth for every order that exists and for every order created
            // by a path that knows nothing about this.
            $table->enum('delivery_payer', ['sender', 'recipient'])->nullable()->after('value');

            $table->unsignedBigInteger('masar_data_version')->default(0)->after('masar_status_version');
        });

        // One deterministic seeding from the customer this order belongs to.
        // Deterministic because `delivery_orders.customer_id` is a single NOT
        // NULL foreign key: there is exactly one source row and nothing to
        // choose between.
        //
        // A snapshot, and the word is load-bearing. From here on the order owns
        // these values: a later change to the shared profile does not reach
        // them, and a correction to them does not reach the profile.
        DB::statement(<<<'SQL'
UPDATE delivery_orders
INNER JOIN customers ON customers.id = delivery_orders.customer_id
SET delivery_orders.recipient_name = customers.name,
    delivery_orders.recipient_phone = customers.phone
SQL);
    }

    /**
     * Fail closed on anything only Masar can have told us.
     *
     * Not «any data at all» — the backfill fills every row, which would make
     * this irreversible the moment it ran. The narrower and truer question is
     * whether a value can still be reconstructed: a snapshot that still equals
     * its customer's is recoverable by re-running the backfill, while one that
     * differs is a courier's correction and exists nowhere else. An alternate
     * number or a stated payer is in the same position — neither has any other
     * source in this system — and a non-zero `masar_data_version` says
     * corrections have been applied whether or not their values happen to look
     * like the customer's today.
     *
     * The same reasoning `masar_status_version` uses one migration earlier.
     */
    public function down(): void
    {
        $applied = DB::table('delivery_orders')
            ->where('masar_data_version', '>', 0)
            ->orWhereNotNull('recipient_alternate_phone')
            ->orWhereNotNull('delivery_payer')
            ->orWhereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', 'delivery_orders.customer_id')
                    ->where(function ($mismatch) {
                        $mismatch->whereColumn('delivery_orders.recipient_name', '!=', 'customers.name')
                            ->orWhereColumn('delivery_orders.recipient_phone', '!=', 'customers.phone');
                    });
            })
            ->exists();

        if ($applied) {
            throw new RuntimeException('Cannot remove the Masar data channel columns while they hold corrections the shared customer profile cannot restore.');
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn([
                'recipient_name',
                'recipient_phone',
                'recipient_alternate_phone',
                'delivery_payer',
                'masar_data_version',
            ]);
        });
    }
};
