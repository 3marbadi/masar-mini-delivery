<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The receiving side of the Masar → Mini Delivery channel (CONTRACT §3.21).
 *
 * Three tables, and each answers one question the channel cannot work without:
 * who is allowed to call (`masar_integration_clients`), what proves it right now
 * (`masar_integration_tokens`), and what has already been applied so a retry is
 * safe (`masar_integration_events`).
 *
 * The shape follows Masar's own `integration_clients` / `integration_events`
 * deliberately: this is the same server-to-server pattern with the two parties
 * exchanged, and inventing a second convention for it would make the two halves
 * of one integration read as unrelated systems. The one difference is the token
 * store — Masar issues its tokens through Sanctum, which is not installed here,
 * so tokens are held as hashes in a table of their own rather than by pulling a
 * package in for a single endpoint.
 *
 * Nothing here touches `delivery_orders`. The status this channel carries is
 * written to columns that already exist (`delivery_status`, `status_reason`),
 * added in the iteration-three data-layer migration and never yet written by
 * anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masar_integration_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // The credential pair of §3.21.2. The secret is stored hashed and
            // shown once at creation — the console command that makes a client
            // prints it and never stores the plaintext.
            $table->string('client_id', 100)->unique();
            $table->string('client_secret_hash');

            // Disabling a client must take effect at once, so it is checked on
            // every request rather than only at token issue (§3.21.7's 403).
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
        });

        Schema::create('masar_integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masar_integration_client_id')->constrained()->cascadeOnDelete();

            // SHA-256 of the plaintext. The plaintext exists only in the
            // response that issued it: a leaked database gives no usable
            // bearer token, which is the whole reason for hashing something
            // that is already random.
            $table->char('token_hash', 64)->unique();

            $table->dateTime('expires_at');
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->index('expires_at', 'masar_integration_token_expiry_index');
        });

        Schema::create('masar_integration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masar_integration_client_id')->constrained()->restrictOnDelete();

            // One id per HTTP request, for correlating a report from Masar with
            // a row here. Distinct from `event_id`, which identifies the logical
            // event across every retry of it.
            $table->uuid('request_id')->index();
            $table->uuid('event_id');

            $table->string('event_type', 64);

            // The order as Masar addressed it — Mini Delivery's own id, sent as
            // a string on the wire (§3.21.4). Kept as sent, even when it names
            // no order here, because "what were we asked about" is the first
            // question of any later investigation.
            $table->string('external_order_id', 128)->nullable();

            // Resolved, when it resolved. Null for a rejected event, and for one
            // naming an order that does not exist.
            $table->foreignId('delivery_order_id')->nullable()->constrained()->restrictOnDelete();

            // What separates a retry from a reuse (§3.21.6). The same digest
            // Masar computes over the same canonical envelope; compared with
            // hash_equals, never with a loose comparison.
            $table->char('payload_hash', 64);

            // §3.21.11 — `ignored_stale` is a *successful* convergence outcome
            // and sits with the other two, not with `rejected`. It means a newer
            // announcement had already been applied, so the sender learned what
            // it needed to and nothing was wrong. Filing it as an error would
            // make an ordinary correction-then-retry look like a fault every
            // time one happened.
            $table->enum('result', ['processed', 'already_processed', 'ignored_stale', 'rejected']);

            // The version this event carried (§3.21.11). Kept even when the
            // event was ignored or refused: "which version did they send" is
            // the first question when a mirror and its source disagree, and it
            // is unanswerable from the order row, which only holds the version
            // that won.
            $table->unsignedBigInteger('status_version')->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            // The idempotency identity of §3.21.6, held by the database rather
            // than by a check-then-write: two copies of one retry arriving
            // together are decided here, not by whichever read first.
            $table->unique(['masar_integration_client_id', 'event_id'], 'masar_integration_event_idempotency_unique');
            $table->index(['delivery_order_id', 'received_at'], 'masar_integration_event_order_index');
        });

        // An applied event names the order it changed; a rejected one changed
        // nothing and has a code saying why. Stated in the database because the
        // audit value of this table depends on the two never drifting apart.
        DB::statement(<<<'SQL'
ALTER TABLE masar_integration_events ADD CONSTRAINT masar_integration_event_outcome_check CHECK (
    (result IN ('processed', 'already_processed', 'ignored_stale') AND error_code IS NULL)
    OR (result = 'rejected' AND error_code IS NOT NULL)
)
SQL);
    }

    /**
     * The event log is idempotency evidence: it is what makes Masar's retry
     * answer the same way the original did. Dropping it while rows exist would
     * turn every in-flight retry into a second application of a change that has
     * already happened, so the rollback refuses while any of it remains.
     */
    public function down(): void
    {
        if (Schema::hasTable('masar_integration_events') && DB::table('masar_integration_events')->exists()) {
            throw new RuntimeException('Cannot drop masar_integration_events while inbound Masar event history exists.');
        }

        Schema::dropIfExists('masar_integration_events');
        Schema::dropIfExists('masar_integration_tokens');
        Schema::dropIfExists('masar_integration_clients');
    }
};
