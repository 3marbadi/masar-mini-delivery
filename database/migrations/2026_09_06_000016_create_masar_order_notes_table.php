<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The notes Masar's couriers wrote on our orders (CONTRACT §13.16.7, v5.5).
 *
 * A table of its own, and §13.16.7 rules out the obvious alternative in as many
 * words: appending into one text column on the order. That would lose the
 * author, the instant and the identity of each note; it would make idempotency
 * impossible, because nothing afterwards could tell whether a given remark had
 * been appended once or twice; and it would mix this log with
 * `delivery_orders`' own inbound notion of a note, which is a different thing
 * with a different owner (§13.16.4).
 *
 * Append-only, mirroring the source. Masar's `notes` is never edited and never
 * deleted (§13.5), so there is no `updated_at` here and nothing in the
 * application writes a stored row a second time.
 *
 * `UNIQUE(delivery_order_id, masar_note_id)` is the channel's whole uniqueness
 * rule (§13.16.3). There is deliberately no version column: §13.16.2 refuses a
 * sequence for notes, because two notes on one order are two independent facts
 * rather than two versions of one — so a high-water mark would discard a note
 * whose retry arrived late, losing a row from an append-only log.
 *
 * DATETIME rather than TIMESTAMP, as everything on this integration is: TIMESTAMP
 * is converted between the session zone and UTC on every read, so a later zone
 * change would retroactively move stored moments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masar_order_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_order_id')->constrained()->restrictOnDelete();

            // §13.16.1 — `notes.id` at Masar. The identity of the fact, and what
            // makes a second announcement of one note recognisable as such.
            $table->unsignedBigInteger('masar_note_id');

            $table->text('content');

            // §13.5, §13.16.1 — the author as Masar fixed it at write time. An
            // id internal to Masar, kept as provenance rather than resolved to
            // anything here: this system has no representative that it names,
            // and the contract is explicit that we neither require nor validate
            // it.
            $table->unsignedBigInteger('masar_representative_id');

            // When the courier wrote it, not when we stored it. The two are
            // different questions and both are worth answering, so both columns
            // exist.
            $table->dateTime('occurred_at');

            // Which announcement delivered this row. Distinct from the
            // idempotency ledger's own copy: that table records what happened to
            // an event, and this records where a stored fact came from — an
            // investigation usually starts from one and needs the other.
            $table->uuid('masar_event_id');

            $table->dateTime('created_at');

            $table->unique(['delivery_order_id', 'masar_note_id'], 'masar_order_note_identity_unique');
            $table->index('delivery_order_id', 'masar_order_note_order_index');
        });

        // §13.5 — a note is never blank at the source, so a blank one here is a
        // transport or contract fault rather than data. Refused at the store as
        // well as at the edge: the request rules can be changed by an edit, and
        // this cannot.
        DB::statement(<<<'SQL'
ALTER TABLE masar_order_notes ADD CONSTRAINT masar_order_note_content_not_blank_check CHECK (
    TRIM(content) <> ''
)
SQL);
    }

    /**
     * Fail-closed while any note has been stored.
     *
     * These rows are the only copy here of something a person wrote in the
     * field, and Masar will not resend them: its own intents are `sent` and it
     * has no mechanism, and no reason, to replay a settled announcement.
     * Dropping the table would lose them silently and irreversibly, so the
     * rollback refuses rather than assuming the operator meant it.
     */
    public function down(): void
    {
        if (Schema::hasTable('masar_order_notes') && DB::table('masar_order_notes')->exists()) {
            throw new RuntimeException('Cannot drop masar_order_notes while stored Masar notes exist.');
        }

        Schema::dropIfExists('masar_order_notes');
    }
};
