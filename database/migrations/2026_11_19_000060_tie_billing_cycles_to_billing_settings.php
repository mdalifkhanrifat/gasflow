<?php

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Billing cycles follow the building's billing settings (owner decision D48,
 * design V7.22):
 *
 * - A cycle names its setting (composite key with building and stream), and
 *   its dates are exactly billing_period_bounds() on its first day: monthly,
 *   one of the two halves, or a short cycle around a change. No cycle while
 *   the stream is off.
 * - Several cycles a month are allowed; cycles of the same stream never
 *   overlap (cancelled ones are left out). period_year and period_month
 *   label the month the cycle ends in.
 * - Dates, stream, setting and reading period are frozen after draft.
 * - The timeline of a stream never changes under its cycles: a new (or
 *   restored) setting starts after the stream's last cycle, and a setting
 *   that shaped a cycle (it starts on or before the day after the last
 *   cycle) cannot be hidden.
 *
 * Settings and cycles of a building take the same transaction lock, so two
 * writes cannot pass the timeline checks at once. The maintenance override
 * passes. Existing cycles get a monthly setting from their first month.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE billing_cycles ADD COLUMN billing_setting_id BIGINT;

            INSERT INTO building_billing_settings(building_id, billing_mode, effective_from, frequency)
            SELECT building_id, billing_mode, date_trunc('month', min(starts_on))::date, 'monthly'
            FROM billing_cycles GROUP BY building_id, billing_mode;
            UPDATE billing_cycles c SET billing_setting_id = s.id
            FROM building_billing_settings s
            WHERE s.building_id = c.building_id AND s.billing_mode = c.billing_mode;

            ALTER TABLE billing_cycles
              ALTER COLUMN billing_setting_id SET NOT NULL,
              ADD CONSTRAINT fk_billing_cycle_setting FOREIGN KEY (billing_setting_id, building_id, billing_mode)
                REFERENCES building_billing_settings(id, building_id, billing_mode),
              DROP CONSTRAINT billing_cycles_building_id_period_year_period_month_unique,
              ADD CONSTRAINT ex_billing_cycles_no_overlap
                EXCLUDE USING gist (
                  building_id WITH =,
                  billing_mode WITH =,
                  daterange(starts_on, ends_on, '[]') WITH &&
                ) WHERE (status <> 'cancelled' AND NOT is_deleted),
              ADD CONSTRAINT ck_billing_cycles_period_of_ends_on
                CHECK (period_year = extract(year FROM ends_on) AND period_month = extract(month FROM ends_on));
            CREATE INDEX idx_billing_cycles_setting ON billing_cycles(billing_setting_id);

            -- One lock per building for its billing timeline.
            CREATE OR REPLACE FUNCTION lock_billing_timeline(p_building_id BIGINT) RETURNS VOID
            LANGUAGE sql AS $$
              SELECT pg_advisory_xact_lock(hashtextextended('billing_timeline:' || p_building_id, 0))
            $$;

            CREATE OR REPLACE FUNCTION validate_billing_cycle_schedule() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE b RECORD;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              PERFORM lock_billing_timeline(NEW.building_id);
              SELECT * INTO b FROM billing_period_bounds(NEW.building_id, NEW.billing_mode, NEW.starts_on);
              IF NOT FOUND THEN
                RAISE EXCEPTION 'Building % has no % billing setting on %', NEW.building_id, NEW.billing_mode, NEW.starts_on
                  USING HINT = 'Add a building billing setting first.';
              END IF;
              IF b.frequency = 'off' THEN
                RAISE EXCEPTION '% billing of building % is off on %', NEW.billing_mode, NEW.building_id, NEW.starts_on;
              END IF;
              IF b.billing_setting_id IS DISTINCT FROM NEW.billing_setting_id THEN
                RAISE EXCEPTION 'A cycle starting % follows billing setting %, not %',
                  NEW.starts_on, b.billing_setting_id, NEW.billing_setting_id;
              END IF;
              IF (NEW.starts_on, NEW.ends_on) IS DISTINCT FROM (b.starts_on, b.ends_on) THEN
                RAISE EXCEPTION 'The % cycle of building % from % runs % to %, not % to %',
                  b.frequency, NEW.building_id, NEW.starts_on, b.starts_on, b.ends_on, NEW.starts_on, NEW.ends_on;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_billing_cycles_schedule
            BEFORE INSERT OR UPDATE OF building_id, billing_mode, billing_setting_id, starts_on, ends_on ON billing_cycles
            FOR EACH ROW EXECUTE FUNCTION validate_billing_cycle_schedule();

            CREATE OR REPLACE FUNCTION guard_billing_setting_timeline() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE last_end DATE;
            BEGIN
              IF lifecycle_override_active() THEN
                RETURN NEW;
              END IF;
              PERFORM lock_billing_timeline(NEW.building_id);
              SELECT max(c.ends_on) INTO last_end FROM billing_cycles c
              WHERE c.building_id = NEW.building_id AND c.billing_mode = NEW.billing_mode
                AND c.status <> 'cancelled' AND NOT c.is_deleted;
              IF last_end IS NULL THEN
                RETURN NEW;
              END IF;
              IF NOT NEW.is_deleted AND (TG_OP = 'INSERT' OR OLD.is_deleted) AND NEW.effective_from <= last_end THEN
                RAISE EXCEPTION '% billing of building % is billed until %: a new setting starts after that',
                  NEW.billing_mode, NEW.building_id, last_end
                  USING HINT = 'Correct a wrong bill by void and a new invoice.';
              END IF;
              IF TG_OP = 'UPDATE' AND NEW.is_deleted AND NOT OLD.is_deleted AND NEW.effective_from <= last_end + 1 THEN
                RAISE EXCEPTION 'Billing setting % shaped cycles until %: it can no longer be removed', NEW.id, last_end;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_building_billing_settings_timeline
            BEFORE INSERT OR UPDATE OF is_deleted ON building_billing_settings
            FOR EACH ROW EXECUTE FUNCTION guard_billing_setting_timeline();
            SQL);

        LifecycleSchema::freezeColumns('billing_cycles',
            ['building_id', 'reading_period_id', 'billing_mode', 'billing_setting_id', 'period_year', 'period_month',
                'starts_on', 'ends_on'],
            editableStatuses: ['draft'], name: 'schedule');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_billing_cycles_freeze_schedule ON billing_cycles;
            DROP TRIGGER IF EXISTS trg_building_billing_settings_timeline ON building_billing_settings;
            DROP TRIGGER IF EXISTS trg_billing_cycles_schedule ON billing_cycles;
            DROP FUNCTION IF EXISTS guard_billing_setting_timeline();
            DROP FUNCTION IF EXISTS validate_billing_cycle_schedule();
            DROP FUNCTION IF EXISTS lock_billing_timeline(BIGINT);
            DROP INDEX IF EXISTS idx_billing_cycles_setting;
            ALTER TABLE billing_cycles
              DROP CONSTRAINT ck_billing_cycles_period_of_ends_on,
              DROP CONSTRAINT ex_billing_cycles_no_overlap,
              ADD CONSTRAINT billing_cycles_building_id_period_year_period_month_unique
                UNIQUE (building_id, period_year, period_month),
              DROP CONSTRAINT fk_billing_cycle_setting,
              DROP COLUMN billing_setting_id;
            SQL);
    }
};
