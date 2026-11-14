<?php

use App\Enums\Lifecycle\HardDeletePolicy;
use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 4, part 2: who may work in a building (design 02 §2, §15 and
 * §16, V7.7).
 *
 * - building_access_grants: another organization (gas vendor, manager, meter
 *   reader, auditor) may work in a building. Each grant carries the building's
 *   tenant, kept consistent by a composite FK; the grant's row-level security
 *   (group 15) relies on it.
 * - building_memberships: a member of an organization is placed in a building.
 *   The database refuses it unless the membership is active and its
 *   organization is the building's tenant or holds an active grant today.
 * - building_membership_roles: only building-scoped roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_access_grants', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('grantee_organization_id')->constrained('organizations');
            $table->string('access_role', 40);
            $table->jsonb('permissions')->default('{}');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unsignedBigInteger('building_owner_organization_id');
            $table->unique(['building_id', 'grantee_organization_id', 'access_role', 'valid_from']);
        });

        Schema::create('building_memberships', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('organization_membership_id')->constrained();
            $table->string('status', 20)->default('active');
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_to')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_id', 'organization_membership_id']);
            $table->unique(['id', 'building_id']);
        });

        Schema::create('building_membership_roles', function (Blueprint $table) {
            $table->foreignId('building_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained();
            $table->primary(['building_membership_id', 'role_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE building_access_grants
              ADD CONSTRAINT ck_building_access_grants_access_role
                CHECK (access_role IN ('gas_vendor','building_manager','meter_reader','auditor','read_only')),
              ADD CONSTRAINT ck_building_access_grants_valid_range
                CHECK (valid_to IS NULL OR valid_to >= valid_from),
              ADD CONSTRAINT fk_access_grant_building_owner
                FOREIGN KEY (building_id, building_owner_organization_id)
                REFERENCES buildings(id, tenant_organization_id);

            ALTER TABLE building_memberships
              ADD CONSTRAINT ck_building_memberships_valid_range
                CHECK (valid_to IS NULL OR valid_to > valid_from);

            CREATE OR REPLACE FUNCTION enforce_building_role_scope() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE role_scope VARCHAR(24);
            BEGIN
              SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
              IF role_scope <> 'building' THEN
                RAISE EXCEPTION 'building_membership_roles only accepts building-scoped roles';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER trg_building_role_scope
            BEFORE INSERT OR UPDATE ON building_membership_roles
            FOR EACH ROW EXECUTE FUNCTION enforce_building_role_scope();

            -- A membership may only be placed in a building when its organization
            -- is the building's tenant or holds an active access grant (design §16).
            CREATE OR REPLACE FUNCTION validate_building_membership_access() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE membership_org BIGINT; tenant_org BIGINT;
            BEGIN
              SELECT organization_id INTO membership_org
                FROM organization_memberships WHERE id = NEW.organization_membership_id AND status = 'active';
              IF membership_org IS NULL THEN
                RAISE EXCEPTION 'An active organization membership is required';
              END IF;
              SELECT tenant_organization_id INTO tenant_org FROM buildings WHERE id = NEW.building_id;
              IF tenant_org IS NULL THEN RAISE EXCEPTION 'Building does not exist'; END IF;
              IF membership_org <> tenant_org AND NOT EXISTS (
                SELECT 1 FROM building_access_grants g
                WHERE g.building_id = NEW.building_id
                  AND g.grantee_organization_id = membership_org
                  AND g.status = 'active'
                  AND g.valid_from <= CURRENT_DATE
                  AND (g.valid_to IS NULL OR g.valid_to >= CURRENT_DATE)
              ) THEN
                RAISE EXCEPTION 'Organization has no active grant for this building';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER trg_validate_building_membership_access
            BEFORE INSERT OR UPDATE ON building_memberships
            FOR EACH ROW EXECUTE FUNCTION validate_building_membership_access();
            SQL);

        // Lifecycle policies exactly as design V7.7.
        LifecycleSchema::attach('building_access_grants', module: 'tenancy', class: RecordClass::Workflow, softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('building_memberships', module: 'tenancy', class: RecordClass::Workflow, softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::attach('building_membership_roles', module: 'tenancy', class: RecordClass::Pivot, hardDelete: HardDeletePolicy::Allowed);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('building_access_grants', 'pending', 'Pending', 'warning', NULL,  true,  false, 10, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('building_access_grants', 'active',  'Active',  'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('building_access_grants', 'revoked', 'Revoked', 'danger',  false, false, true,  30, '{"bn":{"label":"প্রত্যাহারকৃত"}}'),
                   ('building_access_grants', 'expired', 'Expired', 'muted',   false, false, true,  40, '{"bn":{"label":"মেয়াদোত্তীর্ণ"}}'),
                   ('building_memberships', 'invited',   'Invited',   'info',    NULL,  true,  false, 10, '{"bn":{"label":"আমন্ত্রিত"}}'),
                   ('building_memberships', 'active',    'Active',    'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('building_memberships', 'suspended', 'Suspended', 'warning', false, false, false, 30, '{"bn":{"label":"স্থগিত"}}'),
                   ('building_memberships', 'revoked',   'Revoked',   'danger',  false, false, true,  40, '{"bn":{"label":"প্রত্যাহারকৃত"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('building_access_grants', 'building_memberships');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('building_access_grants', 'building_memberships', 'building_membership_roles');
            SQL);

        Schema::dropIfExists('building_membership_roles');
        Schema::dropIfExists('building_memberships');
        Schema::dropIfExists('building_access_grants');

        DB::unprepared('DROP FUNCTION IF EXISTS enforce_building_role_scope(); DROP FUNCTION IF EXISTS validate_building_membership_access();');
    }
};
