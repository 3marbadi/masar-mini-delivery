<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_integration_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedBigInteger('current_version')->default(0);
            $table->timestamp('assigned_transmitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_integration_states');
    }
};
