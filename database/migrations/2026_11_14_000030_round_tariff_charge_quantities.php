<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * tariff_charge_segments() returned the last segment's quantity in the scale
 * of the given consumption ("70" instead of "70.000000" for a single rate).
 * Quantities are always given with six decimals, like the stored columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
                  quantity := round(remaining, 6);
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
};
