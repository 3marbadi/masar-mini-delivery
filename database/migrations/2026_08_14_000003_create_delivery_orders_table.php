<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('representative_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->decimal('value', 10, 2);
            $table->string('location_link')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->enum('status', ['new', 'assigned', 'completed', 'cancelled'])
                ->default('new');
            $table->enum('result', ['delivered', 'not_delivered'])->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};
