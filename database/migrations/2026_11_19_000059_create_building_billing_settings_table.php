<?php

use App\Enums\Lifecycle\RecordClass;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How often and how a building bills, per stream (owner decision D48, design
 * V7.22): once or twice a month, or not at all, and how gas charges are split
 * over rate changes (`days` = the daily average).
 *
 * - building_billing_settings: one timeline per building and billing mode.
 *   A row holds from effective_from until the next row of the same stream,
 *   so there are no gaps and no overlaps. Rows are frozen from the first
 *   write: a change is a new row from a later date. Both streams with the
 *   same schedule = "all together"; their cycles and invoices stay apart
 *   because their money goes to different collectors (D47).
 * - building_billing_setting_on(building, mode, day): the governing row.
 * - billing_period_bounds(building, mode, day): the cycle that holds the day,
 *   from the pattern (monthly from cycle_start_day, or the two halves split
 *   at second_start_day) clipped to the setting's range, so the cycles
 *   around a change may be short. For `off` it returns the off range.
 *
 * Start days stop at 28, so every month has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_billing_settings', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->string('billing_mode', 32);
            $table->date('effective_from');
            $table->string('frequency', 16);
            $table->smallInteger('cycle_start_day')->default(1);
            $table->smallInteger('second_start_day')->nullable();
            $table->smallInteger('due_after_days')->default(15);
            $table->string('split_method', 16)->default('auto');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'building_id', 'billing_mode'], 'uq_billing_setting_building_mode');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE building_billing_settings
              ADD CONSTRAINT ck_building_billing_settings_billing_mode
                CHECK (billing_mode IN ('authorized_meter_billing','internal_cost_allocation')),
              ADD CONSTRAINT ck_building_billing_settings_frequency
                CHECK (frequency IN ('monthly','semi_monthly','off')),
              ADD CONSTRAINT ck_building_billing_settings_cycle_start_day
                CHECK (cycle_start_day BETWEEN 1 AND 28),
              -- Twice a month needs the day the second half starts, later in the month.
              ADD CONSTRAINT ck_building_billing_settings_second_start_day
                CHECK ((frequency = 'semi_monthly') = (second_start_day IS NOT NULL)
                       AND (second_start_day IS NULL OR second_start_day BETWEEN cycle_start_day + 1 AND 28)),
              ADD CONSTRAINT ck_building_billing_settings_due_after_days CHECK (due_after_days >= 0),
              ADD CONSTRAINT ck_building_billing_settings_split_method
                CHECK (split_method IN ('auto','own_meter','main_meter','days'));
            SQL);

        LifecycleSchema::attach('building_billing_settings', module: 'billing', class: RecordClass::Configuration);
        LifecycleSchema::freezeColumns('building_billing_settings',
            ['building_id', 'billing_mode', 'effective_from', 'frequency', 'cycle_start_day',
                'second_start_day', 'due_after_days', 'split_method'],
            editableStatuses: [], name: 'written');

        DB::unprepared(<<<'SQL'
            -- One visible row per stream and day; a hidden row frees its day.
            CREATE UNIQUE INDEX uq_building_billing_settings_from
              ON building_billing_settings(building_id, billing_mode, effective_from)
              WHERE NOT is_deleted;

            CREATE OR REPLACE FUNCTION building_billing_setting_on(p_building_id BIGINT, p_billing_mode TEXT, p_on DATE)
            RETURNS building_billing_settings LANGUAGE sql STABLE AS $$
              SELECT s.* FROM building_billing_settings s
              WHERE s.building_id = p_building_id AND s.billing_mode = p_billing_mode
                AND s.effective_from <= p_on AND NOT s.is_deleted
              ORDER BY s.effective_from DESC
              LIMIT 1
            $$;

            CREATE OR REPLACE FUNCTION billing_period_bounds(p_building_id BIGINT, p_billing_mode TEXT, p_on DATE)
            RETURNS TABLE (starts_on DATE, ends_on DATE, billing_setting_id BIGINT, frequency TEXT)
            LANGUAGE plpgsql STABLE AS $$
            DECLARE
              s building_billing_settings;
              next_from DATE;
              first_day DATE;
              second_day DATE;
            BEGIN
              s := building_billing_setting_on(p_building_id, p_billing_mode, p_on);
              IF s.id IS NULL THEN
                RETURN;
              END IF;
              SELECT min(n.effective_from) INTO next_from FROM building_billing_settings n
              WHERE n.building_id = s.building_id AND n.billing_mode = s.billing_mode
                AND n.effective_from > s.effective_from AND NOT n.is_deleted;
              billing_setting_id := s.id;
              frequency := s.frequency;

              first_day := date_trunc('month', p_on)::date + (s.cycle_start_day - 1);
              IF s.frequency = 'off' THEN
                starts_on := s.effective_from;
                ends_on := NULL;
              ELSIF s.frequency = 'monthly' THEN
                starts_on := CASE WHEN p_on >= first_day THEN first_day ELSE (first_day - interval '1 month')::date END;
                ends_on := (starts_on + interval '1 month')::date - 1;
              ELSE
                second_day := date_trunc('month', p_on)::date + (s.second_start_day - 1);
                IF p_on >= second_day THEN
                  starts_on := second_day;
                  ends_on := (first_day + interval '1 month')::date - 1;
                ELSIF p_on >= first_day THEN
                  starts_on := first_day;
                  ends_on := second_day - 1;
                ELSE
                  starts_on := (second_day - interval '1 month')::date;
                  ends_on := first_day - 1;
                END IF;
              END IF;

              -- The cycles around a change are cut at the change.
              starts_on := greatest(starts_on, s.effective_from);
              IF next_from IS NOT NULL THEN
                ends_on := least(COALESCE(ends_on, next_from - 1), next_from - 1);
              END IF;
              RETURN NEXT;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS billing_period_bounds(BIGINT, TEXT, DATE);
            DROP FUNCTION IF EXISTS building_billing_setting_on(BIGINT, TEXT, DATE);
            DELETE FROM lifecycle_entities WHERE entity_name = 'building_billing_settings';
            DROP TABLE IF EXISTS building_billing_settings;
            SQL);
    }
};
