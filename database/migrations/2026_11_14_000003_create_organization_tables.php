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
 * Migration group 3, part 2: organizations, memberships and scope-aware roles
 * (design 02 §1 and §16, V7.7, V7.10, V7.13).
 *
 * Roles have a scope (platform, organization, building). The database refuses
 * a role of the wrong scope on a platform user or an organization membership;
 * application policies still decide what each role may do. The building
 * pieces (building_memberships, their roles) need buildings and come with
 * group 4. Row-level security for these tables comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->string('code', 60)->unique();
            $table->string('name', 180);
            $table->string('organization_type', 32);
            $table->string('legal_name', 180)->nullable();
            $table->string('registration_no', 100)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('default_locale', 8)->default('bn');
            $table->foreign('default_locale')->references('code')->on('supported_locales');
            $table->string('location_tracking_policy', 16)->default('optional');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('membership_role', 40);
            $table->string('status', 24)->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'user_id']);
            $table->unique(['id', 'organization_id']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('code', 80)->unique();
            $table->string('name', 100);
            $table->string('scope', 24);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('code', 120)->unique();
            $table->text('description')->nullable();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('organization_memberships')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained();
            $table->primary(['membership_id', 'role_id']);
        });

        Schema::create('platform_user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained();
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestampTz('granted_at')->useCurrent();
            $table->primary(['user_id', 'role_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations
              ADD CONSTRAINT ck_organizations_type
                CHECK (organization_type IN ('gas_vendor','building_owner','management','operator','other')),
              ADD CONSTRAINT ck_organizations_location_tracking_policy
                CHECK (location_tracking_policy IN ('off','optional','required'));
            ALTER TABLE roles
              ADD CONSTRAINT ck_roles_scope CHECK (scope IN ('platform','organization','building'));

            -- Role scope at the database boundary (design §16).
            CREATE OR REPLACE FUNCTION enforce_platform_role_scope() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE role_scope VARCHAR(24);
            BEGIN
              SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
              IF role_scope <> 'platform' THEN
                RAISE EXCEPTION 'platform_user_roles only accepts platform-scoped roles';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER trg_platform_role_scope
            BEFORE INSERT OR UPDATE ON platform_user_roles
            FOR EACH ROW EXECUTE FUNCTION enforce_platform_role_scope();

            CREATE OR REPLACE FUNCTION enforce_membership_role_scope() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE role_scope VARCHAR(24);
            BEGIN
              SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
              IF role_scope <> 'organization' THEN
                RAISE EXCEPTION 'membership_roles only accepts organization-scoped roles';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER trg_membership_role_scope
            BEFORE INSERT OR UPDATE ON membership_roles
            FOR EACH ROW EXECUTE FUNCTION enforce_membership_role_scope();
            SQL);

        // Lifecycle policies exactly as design V7.7.
        LifecycleSchema::attach('roles', module: 'identity', class: RecordClass::Configuration);
        LifecycleSchema::attach('permissions', module: 'identity', class: RecordClass::Configuration);
        LifecycleSchema::attach('role_permissions', module: 'identity', class: RecordClass::Pivot, hardDelete: HardDeletePolicy::Allowed);
        LifecycleSchema::attach('platform_user_roles', module: 'identity', class: RecordClass::Pivot, hardDelete: HardDeletePolicy::Allowed);
        LifecycleSchema::attach('organizations', module: 'organizations', class: RecordClass::Master, defaultStatus: 'pending');
        LifecycleSchema::attach(
            'organization_memberships',
            module: 'tenancy',
            class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected,
        );
        LifecycleSchema::attach('membership_roles', module: 'tenancy', class: RecordClass::Pivot, hardDelete: HardDeletePolicy::Allowed);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('organizations', 'pending',   'Pending',   'warning', NULL,  true,  false, 10, '{"bn":{"label":"অপেক্ষমাণ"}}'),
                   ('organizations', 'active',    'Active',    'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('organizations', 'suspended', 'Suspended', 'warning', false, false, false, 30, '{"bn":{"label":"স্থগিত"}}'),
                   ('organizations', 'closed',    'Closed',    'muted',   false, false, true,  40, '{"bn":{"label":"বন্ধ"}}'),
                   ('organization_memberships', 'invited',   'Invited',   'info',    NULL,  true,  false, 10, '{"bn":{"label":"আমন্ত্রিত"}}'),
                   ('organization_memberships', 'active',    'Active',    'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('organization_memberships', 'suspended', 'Suspended', 'warning', false, false, false, 30, '{"bn":{"label":"স্থগিত"}}'),
                   ('organization_memberships', 'revoked',   'Revoked',   'danger',  false, false, true,  40, '{"bn":{"label":"প্রত্যাহারকৃত"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('organizations', 'organization_memberships');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('roles', 'permissions', 'role_permissions', 'platform_user_roles',
                                                                 'organizations', 'organization_memberships', 'membership_roles');
            SQL);

        Schema::dropIfExists('platform_user_roles');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');

        DB::unprepared('DROP FUNCTION IF EXISTS enforce_platform_role_scope(); DROP FUNCTION IF EXISTS enforce_membership_role_scope();');
    }
};
