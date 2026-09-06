<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One amount domain across both systems (CONTRACT §13.8.1, §13.8.3).
 *
 * `delivery_orders.value` was DECIMAL(10, 2) and Masar's `orders.order_amount`
 * is DECIMAL(12, 2). The gap is not academic: a courier's correction between
 * 99,999,999.99 and 9,999,999,999.99 is accepted by Masar, committed there,
 * versioned, logged and announced — and could then never be applied here.
 * §13.7 point 8 makes that announcement's failure invisible to the courier, so
 * the two systems would simply diverge, permanently, on an order nobody was told
 * about. A receiver that answers 422 for ever is not a guard; it is a documented
 * way to lose data.
 *
 * The audit before this migration found no business rule behind the narrower
 * width, which is why it is widened rather than escalated:
 *
 *   - the column is the order's monetary value, the same domain Masar's
 *     `order_amount` names, and the outbound snapshot already calls it
 *     `order.amount`;
 *   - the only validation anywhere is Filament's `required, numeric,
 *     minValue(0), step(0.01)` — a floor and a scale, and **no ceiling**;
 *   - there is no CHECK constraint on it;
 *   - nothing computes with it. No SUM, no average, no total: the operational
 *     widgets aggregate `status`, and the one place it is read for display
 *     formats it as money;
 *   - the cast is `decimal:2`, which is about scale and not about range;
 *   - no test asserts an upper bound, and every fixture is a two- or
 *     three-figure amount.
 *
 * So the 10 was storage width inherited from the table's first migration, not a
 * limit anyone decided on. Widening preserves every stored value exactly —
 * DECIMAL(10, 2) is a strict subset of DECIMAL(12, 2), and MySQL's ALTER copies
 * the decimal representation rather than passing it through a float — and it
 * closes the divergence.
 *
 * A second column was considered and rejected. Two amounts on one order is two
 * answers to one question, and every reader would then have to know which.
 */
return new class extends Migration
{
    /** DECIMAL(10, 2)'s ceiling, and therefore the boundary this migration moves past. */
    private const OLD_CEILING = '99999999.99';

    public function up(): void
    {
        // Raw rather than `change()`, so the resulting column definition is
        // stated exactly rather than rebuilt from introspection — NOT NULL is
        // preserved deliberately and not by luck.
        DB::statement('ALTER TABLE delivery_orders MODIFY value DECIMAL(12, 2) NOT NULL');
    }

    /**
     * Fail closed on anything the narrower column could not hold.
     *
     * A narrowing ALTER in MySQL either truncates to the maximum or errors
     * depending on the session's strict mode, and neither is acceptable: the
     * first silently rewrites an order's value and the second fails halfway
     * through with no statement of what went wrong. So the question is asked
     * here, in words, before anything is altered.
     *
     * Compared as a decimal in the database rather than in PHP. A float
     * comparison at this magnitude is exactly the kind of rounding this column's
     * type exists to avoid, and a guard that rounded would be the one thing
     * worse than no guard.
     */
    public function down(): void
    {
        $exceeding = DB::table('delivery_orders')
            ->whereRaw('value > CAST(? AS DECIMAL(12, 2))', [self::OLD_CEILING])
            ->count();

        if ($exceeding > 0) {
            throw new RuntimeException(
                "Cannot narrow delivery_orders.value to DECIMAL(10, 2): {$exceeding} order(s) hold a value above "
                .self::OLD_CEILING.', which the narrower column cannot represent.'
            );
        }

        DB::statement('ALTER TABLE delivery_orders MODIFY value DECIMAL(10, 2) NOT NULL');
    }
};
