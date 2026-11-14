<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every uploaded file belongs to someone (design 02 §4, V7.15): a tenant
 * organization, a building, or both. When both are given, the organization
 * must be allowed to work in the building. Row-level security for attachments
 * (owner organization, or a building the caller can see) comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE attachments
              ADD CONSTRAINT ck_attachments_scope
                CHECK (tenant_organization_id IS NOT NULL OR building_id IS NOT NULL);

            CREATE OR REPLACE FUNCTION validate_attachment_scope() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.tenant_organization_id IS NOT NULL AND NEW.building_id IS NOT NULL
                 AND EXISTS (SELECT 1 FROM buildings WHERE id = NEW.building_id)
                 AND NOT organization_can_access_building(NEW.tenant_organization_id, NEW.building_id) THEN
                RAISE EXCEPTION 'The attachment''s organization has no access to its building';
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_validate_attachment_scope
            BEFORE INSERT OR UPDATE OF tenant_organization_id, building_id ON attachments
            FOR EACH ROW EXECUTE FUNCTION validate_attachment_scope();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_validate_attachment_scope ON attachments;
            DROP FUNCTION IF EXISTS validate_attachment_scope();
            ALTER TABLE attachments DROP CONSTRAINT IF EXISTS ck_attachments_scope;
            SQL);
    }
};
