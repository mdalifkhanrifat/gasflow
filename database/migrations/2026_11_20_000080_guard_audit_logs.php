<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit log is append-only and stays inside its tenant (owner decision
 * D51, design V7.24, group 14 questions 1 and 2):
 *
 * - the runtime roles may add entries and read them, never change them
 *   (as maintenance_override_logs); delete was already refused.
 * - an entry of a building names an organization with access to that
 *   building (organization_can_access_building()); platform entries (no
 *   organization, no building) stay allowed. Secrets are removed by the
 *   app's audit writer (the subject table's redacted_columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE UPDATE ON audit_logs FROM gasflow_app, gasflow_maintenance;

            ALTER TABLE audit_logs ADD CONSTRAINT ck_audit_logs_building_organization
              CHECK (building_id IS NULL OR organization_id IS NOT NULL);

            CREATE OR REPLACE FUNCTION validate_audit_log_scope() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.building_id IS NOT NULL AND NEW.organization_id IS NOT NULL
                 AND NOT organization_can_access_building(NEW.organization_id, NEW.building_id) THEN
                RAISE EXCEPTION 'Organization % has no access to building % of this audit entry', NEW.organization_id, NEW.building_id;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_audit_logs_scope BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION validate_audit_log_scope();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_audit_logs_scope ON audit_logs;
            DROP FUNCTION IF EXISTS validate_audit_log_scope();
            ALTER TABLE audit_logs DROP CONSTRAINT ck_audit_logs_building_organization;
            GRANT UPDATE ON audit_logs TO gasflow_app, gasflow_maintenance;
            SQL);
    }
};
