<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('total_orders')->default(0)->after('is_active');
            $table->unsignedInteger('delivered_orders')->default(0)->after('total_orders');
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_delivered_orders_check CHECK (delivered_orders <= total_orders)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP CHECK customers_delivered_orders_check');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['total_orders', 'delivered_orders']);
        });
    }
};
