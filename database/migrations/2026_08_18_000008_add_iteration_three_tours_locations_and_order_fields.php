<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('location_link')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_tours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('representative_id')->constrained()->restrictOnDelete();
            $table->foreignId('start_location_id')->constrained('locations')->restrictOnDelete();
            $table->dateTime('departure_time');
            $table->timestamps();
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('representative_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('tour_id')->nullable()->after('location_id')->constrained('delivery_tours')->restrictOnDelete();
            $table->enum('location_validation_status', ['pending', 'valid', 'invalid'])->default('pending')->after('longitude');
            $table->enum('readiness_status', ['not_contacted', 'confirmed', 'no_answer', 'not_ready'])->default('not_contacted')->after('location_validation_status');
            $table->dateTime('available_from')->nullable()->after('readiness_status');
            $table->dateTime('available_until')->nullable()->after('available_from');
            $table->dateTime('confirmed_at')->nullable()->after('available_until');
            $table->enum('delivery_status', ['with_rep', 'delivered', 'postponed', 'returned'])->default('with_rep')->after('confirmed_at');
            $table->string('status_reason')->nullable()->after('delivery_status');
            $table->dateTime('location_completed_at')->nullable()->after('status_reason');

            $table->index(['tour_id', 'delivery_status']);
            $table->index(['representative_id', 'readiness_status']);
        });

        DB::table('delivery_orders')->orderBy('id')->each(function (object $order): void {
            $locationId = DB::table('locations')->insertGetId([
                'location_link' => $order->location_link,
                'latitude' => $order->latitude,
                'longitude' => $order->longitude,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ]);

            DB::table('delivery_orders')->where('id', $order->id)->update(['location_id' => $locationId]);
        });

        DB::statement('ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_readiness_window_check CHECK (available_until IS NULL OR available_from IS NULL OR available_until >= available_from)');
        DB::statement("ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_status_reason_check CHECK ((delivery_status IN ('postponed', 'returned') AND status_reason IS NOT NULL) OR (delivery_status IN ('with_rep', 'delivered') AND status_reason IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE delivery_orders DROP CHECK delivery_orders_status_reason_check');
        DB::statement('ALTER TABLE delivery_orders DROP CHECK delivery_orders_readiness_window_check');

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropIndex(['tour_id', 'delivery_status']);
            $table->dropIndex(['representative_id', 'readiness_status']);
            $table->dropConstrainedForeignId('tour_id');
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn([
                'location_validation_status',
                'readiness_status',
                'available_from',
                'available_until',
                'confirmed_at',
                'delivery_status',
                'status_reason',
                'location_completed_at',
            ]);
        });

        Schema::dropIfExists('delivery_tours');
        Schema::dropIfExists('locations');
    }
};
