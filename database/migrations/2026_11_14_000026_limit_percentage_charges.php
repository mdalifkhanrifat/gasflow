<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A percentage charge rule is at most 100% (design 02 §6, V7.16, D43).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE building_charge_rules
              ADD CONSTRAINT ck_building_charge_rules_percentage
                CHECK (calculation_type <> 'percentage' OR amount <= 100)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE building_charge_rules DROP CONSTRAINT IF EXISTS ck_building_charge_rules_percentage');
    }
};
