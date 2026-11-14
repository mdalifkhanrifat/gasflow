<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An ended gas vendor assignment still says who supplied the building on its
 * days, and the tariff timeline prices those days with it (design 02 §14,
 * V7.16, D44). So it may not overlap another assignment either: only a
 * cancelled assignment (which never applied) is left out of the check.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE building_vendor_assignments
              DROP CONSTRAINT ex_building_single_gas_vendor_no_overlap,
              ADD CONSTRAINT ex_building_single_gas_vendor_no_overlap
                EXCLUDE USING gist (
                  building_id WITH =,
                  assignment_type WITH =,
                  daterange(valid_from, CASE WHEN valid_to IS NULL THEN NULL ELSE valid_to + 1 END, '[)') WITH &&
                ) WHERE (status IN ('active','ended') AND assignment_type = 'gas_vendor');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE building_vendor_assignments
              DROP CONSTRAINT ex_building_single_gas_vendor_no_overlap,
              ADD CONSTRAINT ex_building_single_gas_vendor_no_overlap
                EXCLUDE USING gist (
                  building_id WITH =,
                  assignment_type WITH =,
                  daterange(valid_from, CASE WHEN valid_to IS NULL THEN NULL ELSE valid_to + 1 END, '[)') WITH &&
                ) WHERE (status = 'active' AND assignment_type = 'gas_vendor');
            SQL);
    }
};
