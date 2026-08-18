<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_outbox', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->after('last_attempt_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('integration_outbox', function (Blueprint $table) {
            $table->dropColumn('next_attempt_at');
        });
    }
};
