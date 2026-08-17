<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained()->restrictOnDelete();
            $table->enum('change_type', ['location', 'delivery_status', 'readiness', 'data']);
            $table->boolean('affects_route');
            $table->enum('impact_level', ['minor', 'moderate', 'major'])->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('seen_at')->nullable();
            $table->timestamps();

            $table->index(['delivery_order_id', 'occurred_at']);
            $table->index(['affects_route', 'seen_at']);
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained('delivery_tours')->restrictOnDelete();
            $table->foreignId('start_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('order_change_id')->nullable()->unique()->constrained('order_changes')->restrictOnDelete();
            $table->enum('status', ['proposed', 'active', 'rejected'])->default('proposed');
            $table->string('ordering_reason');
            $table->enum('decision', ['accepted', 'rejected'])->nullable();
            $table->string('rejection_reason')->nullable();
            $table->dateTime('built_at');
            $table->timestamps();

            $table->index(['tour_id', 'status']);
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('stop_number');
            $table->dateTime('expected_arrival');
            $table->timestamps();

            $table->unique(['route_id', 'stop_number']);
            $table->unique(['route_id', 'delivery_order_id']);
            $table->index('delivery_order_id');
        });

        DB::statement('ALTER TABLE order_changes ADD CONSTRAINT order_changes_impact_check CHECK ((affects_route = 0 AND impact_level IS NULL) OR (affects_route = 1 AND impact_level IS NOT NULL))');
        DB::statement("ALTER TABLE routes ADD CONSTRAINT routes_decision_check CHECK ((status = 'proposed' AND decision IS NULL AND rejection_reason IS NULL) OR (status = 'active' AND (decision IS NULL OR decision = 'accepted') AND rejection_reason IS NULL) OR (status = 'rejected' AND decision = 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('order_changes');
    }
};
