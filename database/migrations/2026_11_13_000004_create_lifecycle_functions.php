<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 1d: the generic lifecycle trigger functions and the one
 * reusable entry point, lifecycle_attach(). Every domain table migration ends
 * by calling lifecycle_attach() (through the LifecycleSchema migration helper).
 *
 * Copied unchanged from gasflow-design-v7/02-postgresql-schema.sql V7.2 and V7.3,
 * so the behaviour verified by the design SQL tests is kept exactly.
 * See 08-record-lifecycle-standard.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lifecycle_override_active()
            RETURNS BOOLEAN LANGUAGE plpgsql STABLE AS $$
            BEGIN
              IF COALESCE(current_setting('app.maintenance_override', true), '') <> 'on' THEN
                RETURN FALSE;
              END IF;
              IF NOT pg_has_role(current_user, 'gasflow_maintenance', 'MEMBER') THEN
                RAISE EXCEPTION 'Maintenance override refused: role % is not a member of gasflow_maintenance', current_user;
              END IF;
              IF NULLIF(current_setting('app.lifecycle_reason', true), '') IS NULL THEN
                RAISE EXCEPTION 'Maintenance override refused: set app.lifecycle_reason (why this manual change is made)';
              END IF;
              RETURN TRUE;
            END; $$;


            -- Primary-key value(s) of a row as text, for logs ("12" or "3:7" for composite keys).
            CREATE OR REPLACE FUNCTION lifecycle_record_key(p_relid OID, p_row JSONB)
            RETURNS TEXT LANGUAGE sql STABLE AS $$
              SELECT COALESCE(p_row->>'id',
                (SELECT string_agg(p_row->>a.attname, ':' ORDER BY array_position(i.indkey::int2[], a.attnum))
                   FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                  WHERE i.indrelid = p_relid AND i.indisprimary), '?')
            $$;

            -- Resolves which status set applies to a table: its own, or the generic '*' set.
            CREATE OR REPLACE FUNCTION lifecycle_status_set(p_entity TEXT)
            RETURNS TEXT LANGUAGE sql STABLE AS $$
              SELECT CASE WHEN EXISTS (SELECT 1 FROM status_definitions WHERE entity_name = p_entity)
                          THEN p_entity ELSE '*' END
            $$;

            CREATE OR REPLACE FUNCTION lifecycle_guard()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              ent lifecycle_entities%ROWTYPE;
              def status_definitions%ROWTYPE;
              status_set TEXT;
              pk_match TEXT;
              override BOOLEAN;
            BEGIN
              SELECT * INTO ent FROM lifecycle_entities WHERE entity_name = TG_TABLE_NAME;
              IF NOT FOUND THEN
                RAISE EXCEPTION 'Table % is not registered in lifecycle_entities', TG_TABLE_NAME;
              END IF;

              override := ent.override_allowed AND lifecycle_override_active();

              -- 0. DELETE handling (same behaviour for Laravel, SQL console, scripts). Rows are never
              --    physically removed unless hard delete is allowed (pivots, expired idempotency keys):
              --    soft 'allowed'                          -> DELETE becomes is_deleted = TRUE automatically
              --    soft 'protected' + maintenance override -> same (deliberate backend hide)
              --    soft 'protected' without override       -> error (financial / history / log records)
              IF TG_OP = 'DELETE' THEN
                IF ent.hard_delete_policy = 'allowed' THEN
                  RETURN OLD;
                END IF;
                IF ent.soft_delete_policy = 'protected' AND NOT override THEN
                  RAISE EXCEPTION 'Records in % are protected; the app cannot delete them (backend maintenance override only)', TG_TABLE_NAME;
                END IF;
                IF OLD.is_deleted THEN
                  RETURN NULL;                                   -- already in the recycle bin
                END IF;
                SELECT string_agg(format('%I = ($1).%I', a.attname, a.attname), ' AND ')
                  INTO pk_match
                  FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                  WHERE i.indrelid = TG_RELID AND i.indisprimary;
                EXECUTE format('UPDATE %I.%I SET is_deleted = TRUE WHERE %s', TG_TABLE_SCHEMA, TG_TABLE_NAME, pk_match)
                  USING OLD;
                RETURN NULL;                                     -- cancel the physical delete
              END IF;

              -- 1. deleted_at <-> is_deleted synchronisation (either side may be written).
              --    Who deleted and why is recorded in status_change_logs (actor + reason).
              IF TG_OP = 'INSERT' THEN
                IF NEW.deleted_at IS NOT NULL THEN
                  NEW.is_deleted := TRUE;
                ELSIF NEW.is_deleted THEN
                  NEW.deleted_at := now();
                END IF;
              ELSE
                IF NEW.deleted_at IS DISTINCT FROM OLD.deleted_at THEN
                  NEW.is_deleted := (NEW.deleted_at IS NOT NULL);
                ELSIF NEW.is_deleted IS DISTINCT FROM OLD.is_deleted THEN
                  NEW.deleted_at := CASE WHEN NEW.is_deleted THEN COALESCE(NEW.deleted_at, now()) ELSE NULL END;
                END IF;
                NEW.created_at := OLD.created_at;
                NEW.updated_at := now();
              END IF;
              IF ent.soft_delete_policy = 'protected' AND NOT override
                 AND ((TG_OP = 'INSERT' AND NEW.is_deleted)
                      OR (TG_OP = 'UPDATE' AND NEW.is_deleted IS DISTINCT FROM OLD.is_deleted)) THEN
                RAISE EXCEPTION 'Records in % are protected; hiding/restoring needs the backend maintenance override', TG_TABLE_NAME;
              END IF;

              -- 2. status must exist in the catalogue; transitions enforced when configured
              IF TG_OP = 'INSERT' OR NEW.status IS DISTINCT FROM OLD.status THEN
                status_set := lifecycle_status_set(TG_TABLE_NAME);
                SELECT * INTO def FROM status_definitions
                  WHERE entity_name = status_set AND code = NEW.status AND is_active;
                IF NOT FOUND THEN
                  RAISE EXCEPTION 'Status "%" is not defined for %', NEW.status, TG_TABLE_NAME;
                END IF;
                IF ent.enforce_transitions AND NOT override THEN    -- manual backend corrections may jump states
                  IF TG_OP = 'INSERT' AND NOT def.is_initial THEN
                    RAISE EXCEPTION 'Status "%" is not an initial status for %', NEW.status, TG_TABLE_NAME;
                  ELSIF TG_OP = 'UPDATE' AND NOT EXISTS (
                      SELECT 1 FROM status_transitions
                      WHERE entity_name = status_set AND from_code = OLD.status
                        AND to_code = NEW.status AND is_active) THEN
                    RAISE EXCEPTION 'Status transition % -> % is not allowed for %', OLD.status, NEW.status, TG_TABLE_NAME;
                  END IF;
                END IF;
                IF def.implies_active IS NOT NULL THEN
                  NEW.is_active := def.implies_active;
                END IF;
              END IF;

              -- 3. deleted rows are never active; restore re-derives availability from status
              IF NEW.is_deleted THEN
                NEW.is_active := FALSE;
              ELSIF TG_OP = 'UPDATE' AND OLD.is_deleted AND NOT NEW.is_active THEN
                SELECT COALESCE(sd.implies_active, TRUE) INTO NEW.is_active
                  FROM status_definitions sd
                  WHERE sd.entity_name = lifecycle_status_set(TG_TABLE_NAME) AND sd.code = NEW.status;
                NEW.is_active := COALESCE(NEW.is_active, TRUE);
              END IF;

              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_log_change()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              rec JSONB := to_jsonb(NEW);
              key TEXT;
              logs_enabled BOOLEAN;
            BEGIN
              SELECT log_lifecycle_changes INTO logs_enabled FROM lifecycle_entities WHERE entity_name = TG_TABLE_NAME;
              IF NOT COALESCE(logs_enabled, FALSE) THEN RETURN NULL; END IF;
              IF NEW.status IS NOT DISTINCT FROM OLD.status
                 AND NEW.is_active IS NOT DISTINCT FROM OLD.is_active
                 AND NEW.is_deleted IS NOT DISTINCT FROM OLD.is_deleted THEN
                RETURN NULL;
              END IF;
              key := lifecycle_record_key(TG_RELID, rec);
              INSERT INTO status_change_logs(entity_name, record_key, organization_id, actor_user_id,
                from_status, to_status, from_is_active, to_is_active, from_is_deleted, to_is_deleted, reason)
              VALUES (TG_TABLE_NAME, COALESCE(key, '?'), app_current_organization_id(), app_current_user_id(),
                OLD.status, NEW.status, OLD.is_active, NEW.is_active, OLD.is_deleted, NEW.is_deleted,
                NULLIF(current_setting('app.lifecycle_reason', true), ''));
              RETURN NULL;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_override_audit()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE op TEXT;
            BEGIN
              IF NOT lifecycle_override_active() THEN RETURN NULL; END IF;
              op := CASE
                      WHEN TG_OP = 'INSERT' THEN 'insert'
                      WHEN NEW.is_deleted AND NOT OLD.is_deleted THEN 'hide'
                      WHEN OLD.is_deleted AND NOT NEW.is_deleted THEN 'restore'
                      ELSE 'update' END;
              INSERT INTO maintenance_override_logs(entity_name, record_key, operation, old_row, new_row,
                                                    reason, actor_user_id, organization_id)
              VALUES (TG_TABLE_NAME, lifecycle_record_key(TG_RELID, to_jsonb(NEW)), op,
                      CASE WHEN TG_OP = 'UPDATE' THEN to_jsonb(OLD) END, to_jsonb(NEW),
                      current_setting('app.lifecycle_reason', true), app_current_user_id(), app_current_organization_id());
              RETURN NULL;
            END; $$;

            -- Stamps created_by / updated_by and the access session (=> device + location) on every row.
            CREATE OR REPLACE FUNCTION lifecycle_stamp_actor()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'INSERT' THEN
                NEW.created_by := COALESCE(NEW.created_by, app_current_user_id());
                NEW.created_session_id := COALESCE(NEW.created_session_id, app_context('session_id')::BIGINT);
              ELSE
                NEW.created_by := OLD.created_by;
                NEW.created_session_id := OLD.created_session_id;
              END IF;
              NEW.updated_by := app_current_user_id();                     -- NULL = system / job
              NEW.updated_session_id := app_context('session_id')::BIGINT;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_data_change_log()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
              ent lifecycle_entities%ROWTYPE;
              new_j JSONB := to_jsonb(NEW);
              old_j JSONB;
              changed TEXT[];
              old_v JSONB;
              new_v JSONB;
              col TEXT;
              op TEXT;
            BEGIN
              SELECT * INTO ent FROM lifecycle_entities WHERE entity_name = TG_TABLE_NAME;
              IF NOT COALESCE(ent.log_data_changes, FALSE) THEN RETURN NULL; END IF;

              IF TG_OP = 'INSERT' THEN
                op := 'insert';
                SELECT array_agg(k ORDER BY k) INTO changed FROM jsonb_object_keys(new_j) AS k;
                new_v := new_j;
              ELSE
                old_j := to_jsonb(OLD);
                SELECT array_agg(n.key ORDER BY n.key) INTO changed
                  FROM jsonb_each(new_j) n
                  WHERE n.key NOT IN ('updated_at','updated_by','updated_session_id')
                    AND n.value IS DISTINCT FROM old_j -> n.key;
                IF changed IS NULL THEN RETURN NULL; END IF;               -- nothing meaningful changed
                SELECT jsonb_object_agg(k, old_j -> k), jsonb_object_agg(k, new_j -> k)
                  INTO old_v, new_v FROM unnest(changed) AS k;
                op := CASE WHEN NEW.is_deleted AND NOT OLD.is_deleted THEN 'hide'
                           WHEN OLD.is_deleted AND NOT NEW.is_deleted THEN 'restore'
                           ELSE 'update' END;
              END IF;

              FOREACH col IN ARRAY ent.redacted_columns LOOP
                IF new_v ? col THEN new_v := jsonb_set(new_v, ARRAY[col], '"[redacted]"'); END IF;
                IF old_v ? col THEN old_v := jsonb_set(old_v, ARRAY[col], '"[redacted]"'); END IF;
              END LOOP;

              INSERT INTO data_change_logs(entity_name, record_key, operation, changed_columns, old_values, new_values,
                actor_user_id, organization_id, access_session_id, user_device_id, client_type, device_kind,
                ip_address, ip_country, ip_city, geo_lat, geo_lon, geo_accuracy_m, geo_source,
                request_id, reason, via_override)
              VALUES (TG_TABLE_NAME, lifecycle_record_key(TG_RELID, new_j), op, changed, old_v, new_v,
                app_current_user_id(), app_current_organization_id(),
                app_context('session_id')::BIGINT, app_context('device_id')::BIGINT,
                app_context('client_type'), app_context('device_kind'),
                app_context('ip')::INET, app_context('ip_country'), app_context('ip_city'),
                app_context('geo_lat')::NUMERIC, app_context('geo_lon')::NUMERIC,
                app_context('geo_accuracy_m')::NUMERIC, app_context('geo_source'),
                app_context('request_id')::UUID, app_context('lifecycle_reason'),
                COALESCE(app_context('maintenance_override'), '') = 'on');
              RETURN NULL;
            END; $$;

            -- =============================================================================
            -- V7.3 lifecycle_attach(): THE ONE REUSABLE ENTRY POINT
            -- =============================================================================
            -- Registers the table, adds any missing lifecycle columns, replaces legacy
            -- soft-delete triggers/checks and installs the generic triggers. Idempotent.
            -- Laravel migrations call it for every new table (see 04-laravel-project-structure.md).
            -- Adds a FK on p_table(p_column) -> p_ref(id) unless one already exists on that column.
            CREATE OR REPLACE FUNCTION lifecycle_ensure_fk(p_table TEXT, p_column TEXT, p_ref TEXT)
            RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
                WHERE c.contype = 'f' AND c.conrelid = p_table::regclass AND a.attname = p_column
                  AND array_length(c.conkey, 1) = 1) THEN
                EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (%I) REFERENCES %I(id)',
                               p_table, left('fk_' || p_table || '_' || p_column, 63), p_column, p_ref);
              END IF;
            END; $$;

            CREATE OR REPLACE FUNCTION lifecycle_attach(
              p_table TEXT,
              p_module TEXT,
              p_record_class TEXT,
              p_soft_delete TEXT DEFAULT 'allowed',              -- 'allowed' | 'protected'
              p_hard_delete TEXT DEFAULT 'forbidden',
              p_enforce_transitions BOOLEAN DEFAULT FALSE,
              p_default_status TEXT DEFAULT 'active'
            ) RETURNS VOID LANGUAGE plpgsql SET client_min_messages = warning AS $$
            DECLARE has_status BOOLEAN;
            BEGIN
              INSERT INTO lifecycle_entities(entity_name, module, record_class, soft_delete_policy,
                                             hard_delete_policy, enforce_transitions, default_status)
              VALUES (p_table, p_module, p_record_class, p_soft_delete, p_hard_delete,
                      p_enforce_transitions, p_default_status)
              ON CONFLICT (entity_name) DO UPDATE SET
                module = EXCLUDED.module, record_class = EXCLUDED.record_class,
                soft_delete_policy = EXCLUDED.soft_delete_policy, hard_delete_policy = EXCLUDED.hard_delete_policy,
                enforce_transitions = EXCLUDED.enforce_transitions, default_status = EXCLUDED.default_status,
                updated_at = now();

              SELECT EXISTS (SELECT 1 FROM information_schema.columns
                             WHERE table_schema = current_schema() AND table_name = p_table AND column_name = 'status')
                INTO has_status;
              IF NOT has_status THEN
                EXECUTE format('ALTER TABLE %I ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT %L', p_table, p_default_status);
              END IF;
              EXECUTE format('ALTER TABLE %I
                  ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE,
                  ADD COLUMN IF NOT EXISTS is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
                  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ,
                  ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                  ADD COLUMN IF NOT EXISTS created_by BIGINT,
                  ADD COLUMN IF NOT EXISTS updated_by BIGINT,
                  ADD COLUMN IF NOT EXISTS created_session_id BIGINT,
                  ADD COLUMN IF NOT EXISTS updated_session_id BIGINT', p_table);
              -- actor FKs (skipped when the column already had an FK from the original design)
              PERFORM lifecycle_ensure_fk(p_table, 'created_by', 'users');
              PERFORM lifecycle_ensure_fk(p_table, 'updated_by', 'users');
              IF to_regclass('access_sessions') IS NOT NULL THEN
                PERFORM lifecycle_ensure_fk(p_table, 'created_session_id', 'access_sessions');
                PERFORM lifecycle_ensure_fk(p_table, 'updated_session_id', 'access_sessions');
              END IF;

              -- replace V6 per-table soft-delete objects with the standard ones
              EXECUTE format('DROP TRIGGER IF EXISTS %I ON %I', 'trg_' || p_table || '_sync_soft_delete', p_table);
              EXECUTE format('ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I', p_table, 'ck_' || p_table || '_soft_delete_consistency');
              EXECUTE format('ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I', p_table, 'ck_' || p_table || '_lifecycle_deleted');
              EXECUTE format('ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I', p_table, 'ck_' || p_table || '_lifecycle_active');
              EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (is_deleted = (deleted_at IS NOT NULL))',
                             p_table, 'ck_' || p_table || '_lifecycle_deleted');
              EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (NOT (is_deleted AND is_active))',
                             p_table, 'ck_' || p_table || '_lifecycle_active');
              -- partial index so default "live" listings (is_deleted = false) stay small and fast
              EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON %I (status) WHERE NOT is_deleted',
                             left('idx_' || p_table || '_live_status', 63), p_table);

              -- "trg_00_" sorts first so lifecycle rules run before table-specific BEFORE triggers
              EXECUTE format('DROP TRIGGER IF EXISTS trg_00_lifecycle_guard ON %I', p_table);
              EXECUTE format('CREATE TRIGGER trg_00_lifecycle_guard BEFORE INSERT OR UPDATE OR DELETE ON %I
                              FOR EACH ROW EXECUTE FUNCTION lifecycle_guard()', p_table);
              EXECUTE format('DROP TRIGGER IF EXISTS trg_zz_lifecycle_log ON %I', p_table);
              EXECUTE format('CREATE TRIGGER trg_zz_lifecycle_log AFTER UPDATE OF status, is_active, is_deleted, deleted_at ON %I
                              FOR EACH ROW EXECUTE FUNCTION lifecycle_log_change()', p_table);
              EXECUTE format('DROP TRIGGER IF EXISTS trg_zz_override_audit ON %I', p_table);
              EXECUTE format('CREATE TRIGGER trg_zz_override_audit AFTER INSERT OR UPDATE ON %I
                              FOR EACH ROW EXECUTE FUNCTION lifecycle_override_audit()', p_table);
              EXECUTE format('DROP TRIGGER IF EXISTS trg_01_actor_stamp ON %I', p_table);
              EXECUTE format('CREATE TRIGGER trg_01_actor_stamp BEFORE INSERT OR UPDATE ON %I
                              FOR EACH ROW EXECUTE FUNCTION lifecycle_stamp_actor()', p_table);
              EXECUTE format('DROP TRIGGER IF EXISTS trg_zz_data_change_log ON %I', p_table);
              EXECUTE format('CREATE TRIGGER trg_zz_data_change_log AFTER INSERT OR UPDATE ON %I
                              FOR EACH ROW EXECUTE FUNCTION lifecycle_data_change_log()', p_table);
            END; $$;

            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS lifecycle_attach(TEXT, TEXT, TEXT, TEXT, TEXT, BOOLEAN, TEXT);
            DROP FUNCTION IF EXISTS lifecycle_ensure_fk(TEXT, TEXT, TEXT);
            DROP FUNCTION IF EXISTS lifecycle_data_change_log();
            DROP FUNCTION IF EXISTS lifecycle_stamp_actor();
            DROP FUNCTION IF EXISTS lifecycle_override_audit();
            DROP FUNCTION IF EXISTS lifecycle_log_change();
            DROP FUNCTION IF EXISTS lifecycle_guard();
            DROP FUNCTION IF EXISTS lifecycle_status_set(TEXT);
            DROP FUNCTION IF EXISTS lifecycle_record_key(OID, JSONB);
            DROP FUNCTION IF EXISTS lifecycle_override_active();
            SQL);
    }
};
