<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE order_changes DROP CHECK order_changes_impact_check');
        DB::statement("ALTER TABLE order_changes MODIFY impact_level ENUM('none', 'minor', 'moderate', 'major') NULL");
        DB::statement('ALTER TABLE order_changes ADD CONSTRAINT order_changes_impact_check CHECK (affects_route = 1 OR impact_level IS NULL)');
    }

    public function down(): void
    {
        $incompatibleRows = DB::table('order_changes')
            ->where('impact_level', 'none')
            ->orWhere(function ($query): void {
                $query->where('affects_route', true)->whereNull('impact_level');
            })
            ->count();

        if ($incompatibleRows > 0) {
            throw new RuntimeException('Cannot restore the old impact semantics while classified-none or pending-classification changes exist.');
        }

        DB::statement('ALTER TABLE order_changes DROP CHECK order_changes_impact_check');
        DB::statement("ALTER TABLE order_changes MODIFY impact_level ENUM('minor', 'moderate', 'major') NULL");
        DB::statement('ALTER TABLE order_changes ADD CONSTRAINT order_changes_impact_check CHECK ((affects_route = 0 AND impact_level IS NULL) OR (affects_route = 1 AND impact_level IS NOT NULL))');
    }
};
