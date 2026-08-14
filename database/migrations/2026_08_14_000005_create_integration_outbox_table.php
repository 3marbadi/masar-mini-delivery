<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('delivery_order_id')
                ->constrained()
                ->restrictOnDelete();
            $table->enum('event_type', [
                'order.assigned',
                'order.updated',
                'order.reassigned',
                'order.cancelled',
            ]);
            $table->unsignedBigInteger('order_version');
            $table->json('payload');
            $table->enum('status', ['pending', 'sent', 'failed'])
                ->default('pending')
                ->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['delivery_order_id', 'order_version']);
        });

        DB::statement('ALTER TABLE integration_outbox ADD CONSTRAINT integration_outbox_order_version_check CHECK (order_version >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
    }
};
