<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A durable identity for the three entities that cross the Masar boundary.
 *
 * Until now the integration sent `(string) $model->getKey()` as
 * `external_courier_id`, `external_customer_id` and `external_order_id` — the
 * auto-increment primary key. That key is unique inside one incarnation of this
 * database and nowhere else, so rebuilding or replacing the database hands the
 * next courier the integer the previous one had, and Masar's mapping — keyed on
 * `(integration_client_id, external_courier_id)` — resolves the new person to
 * the old representative. It is not a lookup failure: it is the success branch
 * returning a confident wrong answer, and the orders, tours and (once they
 * exist) the login of the earlier courier come with it.
 *
 * So identity moves off the primary key and into a column of its own. The key
 * keeps every local relationship it already owns; this column is the only thing
 * the wire ever sees. The two never swap roles.
 *
 * UUIDv7 rather than v4 because it is already this codebase's generator —
 * IntegrationEventGenerationService mints `event_id` with `Str::uuid7()` — and
 * because being time-ordered keeps the unique index below from fragmenting the
 * way random v4 values do.
 *
 * Three steps rather than one, so the migration is correct on a populated
 * database as well as an empty one: add the column nullable, give every
 * existing row a value, and only then forbid null and demand uniqueness. A
 * single non-null column added to a table that already holds rows would have no
 * value to put in them.
 */
return new class extends Migration
{
    /** The entities whose identity crosses the integration boundary. */
    private const TABLES = ['representatives', 'customers', 'delivery_orders'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->char('integration_uid', 36)->nullable()->after('id');
            });

            $this->backfill($table);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->char('integration_uid', 36)->nullable(false)->change();
                $blueprint->unique('integration_uid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_integration_uid_unique');
                $blueprint->dropColumn('integration_uid');
            });
        }
    }

    /**
     * One fresh uuid per existing row.
     *
     * Deliberately per-row and not a single SQL expression: every row needs its
     * own value, and MySQL has no generator that would give distinct uuid7s in
     * one `UPDATE`. Paged by `chunkById` so the memory cost stays flat on a
     * table of any size, and keyed on `id` rather than filtered on
     * `integration_uid IS NULL` — a filter on the column being written would
     * shrink the result set underneath its own pagination.
     */
    private function backfill(string $table): void
    {
        DB::table($table)->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['integration_uid' => (string) Str::uuid7()]);
            }
        });
    }
};
