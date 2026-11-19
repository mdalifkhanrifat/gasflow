<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reading periods follow the billing schedule (owner decision D48, design
 * V7.22). Readings are physical, so both streams share a building's periods.
 *
 * - Several periods a month are allowed; periods of a building never overlap
 *   (cancelled ones are left out). period_year and period_month label the
 *   month the period ends in. Dates are frozen after open.
 * - A cycle closes on its period's readings: the period ends on the cycle's
 *   last day and starts inside the cycle. A monthly cycle can therefore
 *   close on the second half-month period of a stream billed twice a month;
 *   its opening readings are the previous period's closing ones. Checked
 *   when the cycle is written and again when it changes status (a draft may
 *   name a period whose dates were still open). The maintenance override
 *   passes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE reading_periods
              DROP CONSTRAINT reading_periods_building_id_period_year_period_month_unique,
              ADD CONSTRAINT ex_reading_periods_no_overlap
                EXCLUDE USING gist (
                  building_id WITH =,
                  daterange(starts_on, ends_on, '[]') WITH &&
                ) WHERE (status <> 'cancelled' AND NOT is_deleted),
              ADD CONSTRAINT ck_reading_periods_period_of_ends_on
                CHECK (period_year = extract(year FROM ends_on) AND period_month = extract(month FROM ends_on));

            CREATE OR REPLACE FUNCTION validate_billing_cycle_reading_period() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE period RECORD;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              SELECT starts_on, ends_on INTO period FROM reading_periods WHERE id = NEW.reading_period_id;
              -- A missing period is reported by its foreign key.
              IF FOUND AND (period.ends_on <> NEW.ends_on OR period.starts_on < NEW.starts_on) THEN
                RAISE EXCEPTION 'Reading period % (% to %) does not close the cycle % to %: it must end on its last day and start inside it',
                  NEW.reading_period_id, period.starts_on, period.ends_on, NEW.starts_on, NEW.ends_on;
              END IF;
              RETURN NEW;
            END; $$;

            -- Named to run after trg_billing_cycles_schedule, which explains wrong dates first.
            CREATE OR REPLACE TRIGGER trg_billing_cycles_with_reading_period
            BEFORE INSERT OR UPDATE OF reading_period_id, starts_on, ends_on, status ON billing_cycles
            FOR EACH ROW EXECUTE FUNCTION validate_billing_cycle_reading_period();
            SQL);

        LifecycleSchema::freezeColumns('reading_periods',
            ['building_id', 'period_year', 'period_month', 'starts_on', 'ends_on'],
            editableStatuses: ['open'], name: 'dates');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_reading_periods_freeze_dates ON reading_periods;
            DROP TRIGGER IF EXISTS trg_billing_cycles_with_reading_period ON billing_cycles;
            DROP FUNCTION IF EXISTS validate_billing_cycle_reading_period();
            ALTER TABLE reading_periods
              DROP CONSTRAINT ck_reading_periods_period_of_ends_on,
              DROP CONSTRAINT ex_reading_periods_no_overlap,
              ADD CONSTRAINT reading_periods_building_id_period_year_period_month_unique
                UNIQUE (building_id, period_year, period_month);
            SQL);
    }
};
