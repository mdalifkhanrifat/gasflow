<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 1c: the append-only lifecycle trails.
 *
 *   status_change_logs         every status / is_active / is_deleted change
 *   maintenance_override_logs  every row written under the backend maintenance override
 *   data_change_logs           every insert/update: who, when, device, location, old -> new
 *
 * Design source: gasflow-design-v7/02-postgresql-schema.sql V7.1, V7.2 (V7.12/V7.13).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_status_change_log_mutation()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'status_change_logs is append-only';
            END; $$;

            CREATE OR REPLACE FUNCTION prevent_append_only_mutation()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION '% is append-only (not even the maintenance override may change it)', TG_TABLE_NAME;
            END; $$;

            -- Status trail -------------------------------------------------------------
            CREATE TABLE status_change_logs (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              entity_name VARCHAR(63) NOT NULL,
              record_key VARCHAR(120) NOT NULL,
              organization_id BIGINT,
              actor_user_id BIGINT,
              from_status VARCHAR(32),
              to_status VARCHAR(32),
              from_is_active BOOLEAN,
              to_is_active BOOLEAN,
              from_is_deleted BOOLEAN,
              to_is_deleted BOOLEAN,
              reason TEXT,
              changed_at TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_status_change_logs_record ON status_change_logs(entity_name, record_key);
            CREATE INDEX idx_status_change_logs_changed_brin ON status_change_logs USING brin(changed_at);
            ALTER TABLE status_change_logs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE status_change_logs FORCE ROW LEVEL SECURITY;
            CREATE POLICY status_change_logs_scope ON status_change_logs
              USING (organization_id IS NOT DISTINCT FROM app_current_organization_id())
              WITH CHECK (organization_id IS NOT DISTINCT FROM app_current_organization_id());
            CREATE TRIGGER trg_status_change_logs_immutable
            BEFORE UPDATE OR DELETE ON status_change_logs
            FOR EACH ROW EXECUTE FUNCTION prevent_status_change_log_mutation();

            -- Maintenance override trail -------------------------------------------------
            CREATE TABLE maintenance_override_logs (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              entity_name VARCHAR(63) NOT NULL,
              record_key VARCHAR(120) NOT NULL,
              operation VARCHAR(16) NOT NULL CHECK (operation IN ('insert','update','hide','restore')),
              old_row JSONB,
              new_row JSONB,
              reason TEXT NOT NULL,
              db_role VARCHAR(63) NOT NULL DEFAULT current_user,
              actor_user_id BIGINT,
              organization_id BIGINT,
              changed_at TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_maintenance_override_logs_record ON maintenance_override_logs(entity_name, record_key);
            CREATE INDEX idx_maintenance_override_logs_changed_brin ON maintenance_override_logs USING brin(changed_at);
            CREATE TRIGGER trg_maintenance_override_logs_immutable
            BEFORE UPDATE OR DELETE ON maintenance_override_logs
            FOR EACH ROW EXECUTE FUNCTION prevent_append_only_mutation();

            -- Activity trail, partitioned by month for long-term retention -------------
            CREATE TABLE data_change_logs (
              id BIGINT GENERATED ALWAYS AS IDENTITY,
              changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              entity_name VARCHAR(63) NOT NULL,
              record_key VARCHAR(120) NOT NULL,
              operation VARCHAR(16) NOT NULL CHECK (operation IN ('insert','update','hide','restore')),
              changed_columns TEXT[] NOT NULL DEFAULT '{}',
              old_values JSONB,
              new_values JSONB,
              actor_user_id BIGINT,
              organization_id BIGINT,
              access_session_id BIGINT,
              user_device_id BIGINT,
              client_type VARCHAR(16),
              device_kind VARCHAR(16),
              ip_address INET,
              ip_country CHAR(2),
              ip_city VARCHAR(120),
              geo_lat NUMERIC(9,6),
              geo_lon NUMERIC(9,6),
              geo_accuracy_m NUMERIC(10,2),
              geo_source VARCHAR(8),
              request_id UUID,
              reason TEXT,
              via_override BOOLEAN NOT NULL DEFAULT FALSE,
              PRIMARY KEY (id, changed_at)
            ) PARTITION BY RANGE (changed_at);
            CREATE TABLE data_change_logs_default PARTITION OF data_change_logs DEFAULT;
            CREATE INDEX idx_data_change_logs_record ON data_change_logs(entity_name, record_key, changed_at DESC);
            CREATE INDEX idx_data_change_logs_actor ON data_change_logs(actor_user_id, changed_at DESC);
            CREATE INDEX idx_data_change_logs_changed_brin ON data_change_logs USING brin(changed_at);
            ALTER TABLE data_change_logs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE data_change_logs FORCE ROW LEVEL SECURITY;
            CREATE POLICY data_change_logs_scope ON data_change_logs
              USING (organization_id IS NOT DISTINCT FROM app_current_organization_id())
              WITH CHECK (organization_id IS NOT DISTINCT FROM app_current_organization_id());
            CREATE TRIGGER trg_data_change_logs_immutable
            BEFORE UPDATE OR DELETE ON data_change_logs
            FOR EACH ROW EXECUTE FUNCTION prevent_append_only_mutation();

            -- Monthly partition helper (the scheduler calls it ahead of each month).
            CREATE OR REPLACE FUNCTION data_change_logs_ensure_partition(p_month DATE)
            RETURNS VOID LANGUAGE plpgsql AS $$
            DECLARE from_d DATE := date_trunc('month', p_month); part TEXT;
            BEGIN
              part := 'data_change_logs_' || to_char(from_d, 'YYYY_MM');
              IF to_regclass(part) IS NULL THEN
                EXECUTE format('CREATE TABLE %I PARTITION OF data_change_logs FOR VALUES FROM (%L) TO (%L)',
                               part, from_d, (from_d + INTERVAL '1 month')::date);
              END IF;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS data_change_logs_ensure_partition(DATE);
            DROP TABLE IF EXISTS data_change_logs;
            DROP TABLE IF EXISTS maintenance_override_logs;
            DROP TABLE IF EXISTS status_change_logs;
            DROP FUNCTION IF EXISTS prevent_append_only_mutation();
            DROP FUNCTION IF EXISTS prevent_status_change_log_mutation();
            SQL);
    }
};
