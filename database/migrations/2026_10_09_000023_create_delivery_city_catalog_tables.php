<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog of delivery destinations and their prices (PLAN D1 §4.1).
 *
 * Mini Delivery is the origin of this list, not a copy of someone else's: the
 * employee picks a city and, where the city demands one, a region, and the
 * city's price is what the order is charged. Masar receives those choices with
 * the order and stores them; it never keeps a price list of its own, and
 * nothing here is derived from what Masar sends back.
 *
 * **Why the source identifier and not the name.** Both tables carry the id the
 * source file gave the record, unique, and every import keys on it. Names are
 * the one thing in this data certain to be edited — a spelling, a diacritic, a
 * prefix — and a catalog keyed on them would mint a second row for a city that
 * was only renamed, then quietly strand every order pointing at the first. The
 * internal `id` stays the key orders reference, so a rename touches one column
 * in one row.
 *
 * **`region_code` is not an identifier, and the schema says so.** The file's 221
 * regions carry 11 distinct codes between them — `s5` appears 60 times, `s48`
 * 51 — and 48 regions carry none at all. It is a routing-area label: kept
 * verbatim because the source has it, indexed because looking regions up by it
 * is reasonable, and deliberately *not* unique, since a unique index here would
 * reject the real file on its sixty-first row.
 *
 * **A null price is not a free delivery.** Four cities in the source carry no
 * price at all, and one — «إستلام مكتب» — carries exactly `0.00`. The column is
 * nullable so those two states stay apart: null means *no price has been
 * decided*, and an order for such a city is not a priced order, while `0.00` is
 * a decision someone made. Collapsing them would turn four unpriced cities into
 * four free ones, which is the specific mistake PLAN §4.3 forbids.
 *
 * **Why «إستلام مكتب» gets a classification rather than a deactivation.** It is
 * in the source as city `1`, with a region of the same name, and it is not a
 * place — it is office pickup, a fulfilment mode whose flow does not exist yet
 * (PLAN §9). Deactivating it would record the wrong fact: `is_active` means an
 * operator withdrew a destination from use, and someone restoring it later
 * would have no way to know this row was never a destination to begin with. So
 * the kind is a column, the row keeps its source data intact, and the scope that
 * feeds the order form filters on the kind. When the pickup flow arrives it has
 * a row to attach to instead of an exception to make.
 *
 * **Nothing is deleted by import.** `is_active` exists so a city can leave the
 * order form without leaving the database, because orders reference these rows
 * and a delete would either fail or take history with it. A later source file
 * that omits a city says nothing about whether that city should still be
 * offered, so the importer never infers a withdrawal from an absence.
 */
return new class extends Migration
{
    /**
     * What the row represents operationally.
     *
     * Two values, and no third until a flow needs one: an ordinary geographic
     * destination, and office pickup — the source's «إستلام مكتب», which is a
     * way of handing the parcel over rather than somewhere to take it.
     */
    private const FULFILMENT_KINDS = ['delivery', 'office_pickup'];

    public function up(): void
    {
        Schema::create('delivery_cities', function (Blueprint $table): void {
            $table->id();

            // The file's identifier, and the only key an import matches on.
            $table->unsignedBigInteger('source_city_id')->unique();

            $table->string('name');

            // Null and `0.00` are different answers; see the class note. Twelve
            // digits because the column holds money, and widening a decimal
            // later rewrites the table.
            $table->decimal('delivery_price_lyd', 12, 2)->nullable();

            // Whether the employee must pick a region. Source-owned: nine
            // cities in the file demand one. It is not implied by a city having
            // regions — «ضواحي صبراتة» lists seven and requires none.
            $table->boolean('is_region_required')->default(false);

            // Locally owned, and the one column an import leaves alone on a row
            // that already exists. Whether a destination is offered is an
            // operator's decision; the source file has no opinion on it and must
            // not be able to overturn one.
            $table->boolean('is_active')->default(true);

            $table->enum('fulfilment_kind', self::FULFILMENT_KINDS)->default('delivery');

            // Imported as it stands, and read by nothing. Darb's branch names
            // belong to another system's routing; they are kept so the import is
            // lossless, and they decide nothing here.
            $table->string('darb_branch')->nullable();

            $table->timestamps();

            // The order form's question: which cities may be chosen right now.
            $table->index(['fulfilment_kind', 'is_active'], 'delivery_city_selectable_index');
        });

        Schema::create('delivery_regions', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('source_region_id')->unique();

            // The owning city. `restrictOnDelete` rather than a cascade: a city
            // with regions is not something a stray delete should be able to
            // take with it, and the importer never deletes anything anyway.
            $table->foreignId('city_id')
                ->constrained('delivery_cities')
                ->restrictOnDelete();

            $table->string('name');

            // Verbatim, indexed, and not unique — see the class note. Nullable
            // because 48 of the file's regions carry no code.
            $table->string('region_code')->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('darb_branch')->nullable();

            $table->timestamps();

            $table->index('region_code', 'delivery_region_code_index');

            // The order form's second question: which regions belong to the city
            // just chosen.
            $table->index(['city_id', 'is_active'], 'delivery_region_city_active_index');
        });
    }

    /**
     * Both tables drop without ceremony, and that is a statement about them
     * rather than carelessness.
     *
     * This catalog is derived data: the source file rebuilds it and the import is
     * idempotent, so nothing here is lost that re-running the import would not
     * recreate. The columns that *are* irreplaceable are the snapshots on
     * `delivery_orders` — what a given order was charged, and the names it was
     * charged under — and the migration that adds them refuses to roll back
     * while any of them are filled. That refusal runs first, so an order
     * carrying a historic fee stops this rollback before it starts.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_regions');
        Schema::dropIfExists('delivery_cities');
    }
};
