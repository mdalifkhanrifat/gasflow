<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The tariff timeline: which rate applied on which days, and what a
 * consumption costs across rate changes (design 01 §tariffs, V7.16, D44).
 *
 * Gas prices differ per vendor and can change several times in one month,
 * also retroactively. Billing, retro adjustments and reports all price days
 * the same way through two functions:
 *
 * - tariff_rate_segments(building, tariff code, customer category, from, to):
 *   for every day, the building's gas vendor assignment in force (active or
 *   ended, never cancelled) and that vendor's approved or active tariff
 *   version, preferring the customer category's own rate over the general
 *   one (no category). Consecutive days with the same vendor and version form
 *   one segment. Days without a rate come back as a segment without a tariff,
 *   so a gap is visible instead of silently free.
 * - tariff_charge_segments(..., consumption, unit): splits the consumption
 *   over those segments by days (the daily average) and prices each part. The
 *   last segment takes the rounding remainder, so the parts always add up to
 *   the consumption. A gap or a unit that differs from the tariff's is
 *   refused.
 *
 * Both run as the caller, so row-level security applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tariff_rate_segments(
              p_building_id BIGINT, p_tariff_code TEXT, p_customer_category TEXT, p_from DATE, p_to DATE
            ) RETURNS TABLE (
              segment_from DATE, segment_to DATE, days INTEGER,
              building_vendor_assignment_id BIGINT, vendor_organization_id BIGINT,
              tariff_version_id BIGINT, version_no INTEGER, customer_category VARCHAR,
              rate NUMERIC, unit VARCHAR, currency CHAR(3)
            ) LANGUAGE sql STABLE AS $$
              WITH per_day AS (
                SELECT d::date AS day, va.id AS assignment_id, va.vendor_organization_id AS vendor_id,
                       tv.id AS version_id, tv.version_no, tv.customer_category, tv.rate, tv.unit, tv.currency
                FROM generate_series(p_from, p_to, interval '1 day') AS d
                LEFT JOIN LATERAL (
                  SELECT a.id, a.vendor_organization_id FROM building_vendor_assignments a
                  WHERE a.building_id = p_building_id AND a.assignment_type = 'gas_vendor'
                    AND a.status IN ('active','ended')
                    AND a.valid_from <= d::date AND (a.valid_to IS NULL OR a.valid_to >= d::date)
                  LIMIT 1
                ) va ON true
                LEFT JOIN LATERAL (
                  SELECT t.id, t.version_no, t.customer_category, t.rate, t.unit, t.currency FROM tariff_versions t
                  WHERE t.building_vendor_assignment_id = va.id AND t.tariff_code = p_tariff_code
                    AND t.status IN ('approved','active')
                    AND (t.customer_category = p_customer_category OR t.customer_category IS NULL)
                    AND t.effective_from <= d::date AND (t.effective_to IS NULL OR t.effective_to >= d::date)
                  ORDER BY (t.customer_category IS NULL)
                  LIMIT 1
                ) tv ON true
              ),
              islands AS (
                SELECT *, day - (row_number() OVER (PARTITION BY assignment_id, version_id ORDER BY day))::int AS island
                FROM per_day
              )
              SELECT min(day), max(day), count(*)::int, assignment_id, vendor_id, version_id, version_no,
                     customer_category, rate, unit, currency
              FROM islands
              GROUP BY island, assignment_id, vendor_id, version_id, version_no, customer_category, rate, unit, currency
              ORDER BY min(day)
            $$;

            CREATE OR REPLACE FUNCTION tariff_charge_segments(
              p_building_id BIGINT, p_tariff_code TEXT, p_customer_category TEXT, p_from DATE, p_to DATE,
              p_consumption NUMERIC, p_unit TEXT
            ) RETURNS TABLE (
              segment_from DATE, segment_to DATE, days INTEGER,
              building_vendor_assignment_id BIGINT, vendor_organization_id BIGINT,
              tariff_version_id BIGINT, version_no INTEGER, rate NUMERIC, unit VARCHAR, currency CHAR(3),
              quantity NUMERIC, amount NUMERIC
            ) LANGUAGE plpgsql STABLE AS $$
            DECLARE
              total_days INTEGER;
              seg RECORD;
              remaining NUMERIC;
              seg_count INTEGER;
              seg_index INTEGER := 0;
            BEGIN
              IF p_from IS NULL OR p_to IS NULL OR p_to < p_from THEN
                RAISE EXCEPTION 'Billing period % to % is not a valid range', p_from, p_to;
              END IF;
              IF p_consumption IS NULL OR p_consumption < 0 THEN
                RAISE EXCEPTION 'Consumption must be zero or more, got %', p_consumption;
              END IF;
              total_days := p_to - p_from + 1;
              remaining := p_consumption;

              SELECT count(*) INTO seg_count FROM tariff_rate_segments(p_building_id, p_tariff_code, p_customer_category, p_from, p_to);
              FOR seg IN SELECT * FROM tariff_rate_segments(p_building_id, p_tariff_code, p_customer_category, p_from, p_to) LOOP
                seg_index := seg_index + 1;
                IF seg.tariff_version_id IS NULL THEN
                  RAISE EXCEPTION 'No approved % tariff for building % from % to %',
                    p_tariff_code, p_building_id, seg.segment_from, seg.segment_to;
                END IF;
                IF seg.unit IS DISTINCT FROM p_unit THEN
                  RAISE EXCEPTION 'Tariff version % is priced per %, the consumption is in %', seg.tariff_version_id, seg.unit, p_unit;
                END IF;

                segment_from := seg.segment_from; segment_to := seg.segment_to; days := seg.days;
                building_vendor_assignment_id := seg.building_vendor_assignment_id;
                vendor_organization_id := seg.vendor_organization_id;
                tariff_version_id := seg.tariff_version_id; version_no := seg.version_no;
                rate := seg.rate; unit := seg.unit; currency := seg.currency;
                -- Daily average: each segment gets its share of days; the last one takes the remainder.
                IF seg_index = seg_count THEN
                  quantity := remaining;
                ELSE
                  quantity := round(p_consumption * seg.days / total_days, 6);
                  remaining := remaining - quantity;
                END IF;
                amount := round(quantity * seg.rate, 2);
                RETURN NEXT;
              END LOOP;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS tariff_charge_segments(BIGINT, TEXT, TEXT, DATE, DATE, NUMERIC, TEXT);
            DROP FUNCTION IF EXISTS tariff_rate_segments(BIGINT, TEXT, TEXT, DATE, DATE);
            SQL);
    }
};
