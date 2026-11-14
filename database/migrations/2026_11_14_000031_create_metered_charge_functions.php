<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split a consumption over rate segments by meter readings (design 01, V7.17,
 * D45), on top of the tariff timeline (V7.16).
 *
 * - meter_reading_points(meter): every known reading of a meter as "reading at
 *   the start of day": verified supply change readings, monthly readings
 *   (taken day + 1, building time zone), occupancy handovers, assignment
 *   openings, installation. On a day with several, the most precise wins in
 *   that order.
 * - meter_usage_between(meter, from, to): the usage on those days, spreading
 *   each interval between two points evenly over its days (the first and last
 *   interval also cover the days just outside them). NULL when the meter has
 *   fewer than two points or a reading goes down (reset or rollover).
 * - metered_charge_segments(..., meter, split): like tariff_charge_segments(),
 *   but each segment's share follows a weight:
 *     own_meter  - the billed meter's usage, when it was read at every supply
 *                  change inside the period (exact);
 *     main_meter - the building's main meter usage, when a main meter was read
 *                  at every supply change (the building's usage profile);
 *     days       - the daily average (tariff_charge_segments);
 *     auto       - own_meter, else main_meter, else days.
 *   An explicit method that cannot be used is refused; auto falls back. The
 *   method used is returned for the invoice line snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION meter_reading_points(p_meter_id BIGINT)
            RETURNS TABLE (point_on DATE, reading NUMERIC) LANGUAGE sql STABLE AS $$
              SELECT DISTINCT ON (p.point_on) p.point_on, p.reading FROM (
                SELECT sc.effective_on AS point_on, r.reading, 1 AS priority
                  FROM supply_change_readings r JOIN supply_changes sc ON sc.id = r.supply_change_id
                  WHERE r.meter_id = p_meter_id AND sc.status = 'verified'
                UNION ALL
                SELECT (mr.reading_taken_at AT TIME ZONE b.timezone)::date + 1, mr.current_reading, 2
                  FROM meter_readings mr JOIN buildings b ON b.id = mr.building_id
                  WHERE mr.meter_id = p_meter_id AND mr.status IN ('submitted','approved')
                UNION ALL
                SELECT moa.valid_from, moa.opening_reading, 3
                  FROM meter_occupancy_assignments moa JOIN meter_assignments ma ON ma.id = moa.meter_assignment_id
                  WHERE ma.meter_id = p_meter_id
                UNION ALL
                SELECT moa.valid_to + 1, moa.closing_reading, 3
                  FROM meter_occupancy_assignments moa JOIN meter_assignments ma ON ma.id = moa.meter_assignment_id
                  WHERE ma.meter_id = p_meter_id AND moa.valid_to IS NOT NULL AND moa.closing_reading IS NOT NULL
                UNION ALL
                SELECT ma.valid_from, ma.opening_reading, 4
                  FROM meter_assignments ma WHERE ma.meter_id = p_meter_id AND ma.opening_reading IS NOT NULL
                UNION ALL
                SELECT m.installed_at, m.initial_reading, 5
                  FROM meters m WHERE m.id = p_meter_id AND m.installed_at IS NOT NULL
              ) p
              ORDER BY p.point_on, p.priority
            $$;

            CREATE OR REPLACE FUNCTION meter_usage_between(p_meter_id BIGINT, p_from DATE, p_to DATE)
            RETURNS NUMERIC LANGUAGE sql STABLE AS $$
              WITH pts AS (
                SELECT point_on, reading,
                       lead(point_on) OVER (ORDER BY point_on) AS next_on,
                       lead(reading) OVER (ORDER BY point_on) AS next_reading
                FROM meter_reading_points(p_meter_id)
              ),
              intervals AS (
                SELECT point_on, next_on, (next_reading - reading) / (next_on - point_on) AS daily
                FROM pts WHERE next_on IS NOT NULL
              ),
              bounds AS (SELECT min(point_on) AS first_on, max(next_on) AS last_on FROM intervals),
              per_day AS (
                SELECT (SELECT i.daily FROM intervals i, bounds b
                        WHERE (i.point_on <= d::date AND d::date < i.next_on)
                           OR (d::date < b.first_on AND i.point_on = b.first_on)
                           OR (d::date >= b.last_on AND i.next_on = b.last_on)
                        ORDER BY i.point_on LIMIT 1) AS daily
                FROM generate_series(p_from, p_to, interval '1 day') AS d
              )
              SELECT CASE WHEN bool_or(daily IS NULL) OR bool_or(daily < 0) THEN NULL ELSE sum(daily) END
              FROM per_day
            $$;

            CREATE OR REPLACE FUNCTION metered_charge_segments(
              p_building_id BIGINT, p_tariff_code TEXT, p_customer_category TEXT, p_from DATE, p_to DATE,
              p_consumption NUMERIC, p_unit TEXT, p_meter_id BIGINT, p_split TEXT DEFAULT 'auto'
            ) RETURNS TABLE (
              segment_from DATE, segment_to DATE, days INTEGER,
              building_vendor_assignment_id BIGINT, vendor_organization_id BIGINT,
              tariff_version_id BIGINT, version_no INTEGER, rate NUMERIC, unit VARCHAR, currency CHAR(3),
              quantity NUMERIC, amount NUMERIC, split_method TEXT
            ) LANGUAGE plpgsql STABLE AS $$
            DECLARE
              boundaries DATE[];
              candidate TEXT;
              method TEXT;
              meter_ids BIGINT[];
              weights NUMERIC[];
              weight_total NUMERIC;
              remaining NUMERIC := p_consumption;
              seg RECORD;
              i INTEGER := 0;
              n INTEGER;
            BEGIN
              IF p_split IS NULL OR p_split NOT IN ('auto','own_meter','main_meter','days') THEN
                RAISE EXCEPTION 'Unknown split method %: use auto, own_meter, main_meter or days', p_split;
              END IF;

              -- The daily-average rows also validate the period, consumption, gaps and unit.
              SELECT count(*) INTO n FROM tariff_charge_segments(p_building_id, p_tariff_code, p_customer_category, p_from, p_to, p_consumption, p_unit);
              SELECT COALESCE(array_agg(s.segment_from ORDER BY s.segment_from), '{}') INTO boundaries
                FROM tariff_rate_segments(p_building_id, p_tariff_code, p_customer_category, p_from, p_to) s
                WHERE s.segment_from > p_from;

              IF n = 1 THEN
                method := 'single_rate';
              END IF;

              -- Try the requested method, or for auto the most precise one first.
              FOREACH candidate IN ARRAY CASE WHEN p_split = 'auto' THEN ARRAY['own_meter','main_meter','days'] ELSE ARRAY[p_split] END LOOP
                EXIT WHEN method IS NOT NULL;
                IF candidate = 'days' THEN
                  method := 'days';
                  EXIT;
                END IF;

                -- The meters read (verified) at every supply change inside the period.
                SELECT COALESCE(array_agg(m.id), '{}') INTO meter_ids FROM meters m
                  WHERE m.building_id = p_building_id
                    AND CASE WHEN candidate = 'own_meter' THEN m.id = p_meter_id ELSE m.meter_type = 'main' END
                    AND NOT EXISTS (
                      SELECT 1 FROM unnest(boundaries) b WHERE NOT EXISTS (
                        SELECT 1 FROM supply_changes sc JOIN supply_change_readings r ON r.supply_change_id = sc.id
                        WHERE sc.building_id = p_building_id AND sc.status = 'verified' AND sc.effective_on = b AND r.meter_id = m.id));
                IF cardinality(meter_ids) = 0 THEN
                  CONTINUE WHEN p_split = 'auto';
                  IF candidate = 'own_meter' THEN
                    RAISE EXCEPTION 'Meter % was not read at every supply change from % to %', p_meter_id, p_from, p_to;
                  END IF;
                  RAISE EXCEPTION 'No main meter of building % was read at every supply change from % to %', p_building_id, p_from, p_to;
                END IF;

                SELECT array_agg(w ORDER BY s.segment_from) INTO weights
                  FROM tariff_rate_segments(p_building_id, p_tariff_code, p_customer_category, p_from, p_to) s,
                  LATERAL (SELECT CASE WHEN bool_or(u IS NULL) THEN NULL ELSE sum(u) END AS w
                           FROM (SELECT meter_usage_between(mid, s.segment_from, s.segment_to) AS u FROM unnest(meter_ids) mid) x) wt;
                SELECT sum(w) INTO weight_total FROM unnest(weights) w;
                IF weight_total IS NULL OR weight_total <= 0 OR EXISTS (SELECT 1 FROM unnest(weights) w WHERE w IS NULL) THEN
                  CONTINUE WHEN p_split = 'auto';
                  RAISE EXCEPTION 'The % readings from % to % are incomplete or go down; they cannot split the consumption', candidate, p_from, p_to;
                END IF;
                method := candidate;
              END LOOP;

              FOR seg IN SELECT * FROM tariff_charge_segments(p_building_id, p_tariff_code, p_customer_category, p_from, p_to, p_consumption, p_unit) LOOP
                i := i + 1;
                segment_from := seg.segment_from; segment_to := seg.segment_to; days := seg.days;
                building_vendor_assignment_id := seg.building_vendor_assignment_id;
                vendor_organization_id := seg.vendor_organization_id;
                tariff_version_id := seg.tariff_version_id; version_no := seg.version_no;
                rate := seg.rate; unit := seg.unit; currency := seg.currency;
                split_method := method;
                IF method IN ('own_meter','main_meter') THEN
                  IF i = n THEN
                    quantity := remaining;
                  ELSE
                    quantity := round(p_consumption * weights[i] / weight_total, 6);
                    remaining := remaining - quantity;
                  END IF;
                  amount := round(quantity * seg.rate, 2);
                ELSE
                  quantity := seg.quantity;
                  amount := seg.amount;
                END IF;
                RETURN NEXT;
              END LOOP;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS metered_charge_segments(BIGINT, TEXT, TEXT, DATE, DATE, NUMERIC, TEXT, BIGINT, TEXT);
            DROP FUNCTION IF EXISTS meter_usage_between(BIGINT, DATE, DATE);
            DROP FUNCTION IF EXISTS meter_reading_points(BIGINT);
            SQL);
    }
};
