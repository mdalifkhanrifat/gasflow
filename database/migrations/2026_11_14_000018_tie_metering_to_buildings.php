<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A meter is only used inside its own building (design 02 §5, V7.15).
 *
 * meter_assignments and meter_readings carry the building, and composite
 * foreign keys make the meter, the flat and the reading period agree with it:
 * a meter of building A can no longer be placed in a flat of building B or be
 * read in building B's period. Billed occupancies follow through their meter
 * assignment and flat. The column also lets the row-level security policies
 * of group 15 check the building directly.
 *
 * Existing rows are filled from their meter / period before the column
 * becomes NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE reading_periods
              ADD CONSTRAINT uq_reading_period_building UNIQUE (id, building_id);

            ALTER TABLE meter_assignments ADD COLUMN building_id BIGINT;
            UPDATE meter_assignments ma SET building_id = m.building_id FROM meters m WHERE m.id = ma.meter_id;
            ALTER TABLE meter_assignments
              ALTER COLUMN building_id SET NOT NULL,
              ADD CONSTRAINT fk_meter_assignment_meter_building
                FOREIGN KEY (meter_id, building_id) REFERENCES meters(id, building_id),
              ADD CONSTRAINT fk_meter_assignment_flat_building
                FOREIGN KEY (flat_id, building_id) REFERENCES flats(id, building_id);
            CREATE INDEX idx_meter_assignments_building ON meter_assignments(building_id);

            ALTER TABLE meter_readings ADD COLUMN building_id BIGINT;
            UPDATE meter_readings mr SET building_id = rp.building_id FROM reading_periods rp WHERE rp.id = mr.reading_period_id;
            ALTER TABLE meter_readings
              ALTER COLUMN building_id SET NOT NULL,
              ADD CONSTRAINT fk_meter_reading_period_building
                FOREIGN KEY (reading_period_id, building_id) REFERENCES reading_periods(id, building_id),
              ADD CONSTRAINT fk_meter_reading_meter_building
                FOREIGN KEY (meter_id, building_id) REFERENCES meters(id, building_id);
            CREATE INDEX idx_meter_readings_building ON meter_readings(building_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE meter_readings DROP COLUMN IF EXISTS building_id;
            ALTER TABLE meter_assignments DROP COLUMN IF EXISTS building_id;
            ALTER TABLE reading_periods DROP CONSTRAINT IF EXISTS uq_reading_period_building;
            SQL);
    }
};
