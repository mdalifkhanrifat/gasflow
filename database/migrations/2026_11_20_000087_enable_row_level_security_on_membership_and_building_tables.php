<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-level security on the tenant tables the design left without a policy
 * (owner decision D52, design V7.25, group 15 question 3), ENABLE + FORCE:
 *
 * - billing_disputes, building_billing_settings, building_vendor_assignments,
 *   tariff_retro_adjustments: the design's tenant_scope pattern, visible and
 *   writable when their building is visible.
 * - organization_memberships: visible to their organization and to their own
 *   user (a user sees where they belong); written only by their organization.
 * - membership_roles, building_membership_roles: visible with their
 *   membership; written only for a membership of the context organization.
 * - user_devices: only their own user.
 *
 * users, organizations and platform_user_roles wait for Phase 4 (login reads
 * them before any organization context exists). Catalogues, plans and
 * licensing are platform tables without row-level security.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE POLICY tenant_scope_billing_disputes ON billing_disputes
              USING (EXISTS (SELECT 1 FROM buildings b WHERE b.id = billing_disputes.building_id))
              WITH CHECK (EXISTS (SELECT 1 FROM buildings b WHERE b.id = billing_disputes.building_id));

            CREATE POLICY tenant_scope_building_billing_settings ON building_billing_settings
              USING (EXISTS (SELECT 1 FROM buildings b WHERE b.id = building_billing_settings.building_id))
              WITH CHECK (EXISTS (SELECT 1 FROM buildings b WHERE b.id = building_billing_settings.building_id));

            CREATE POLICY tenant_scope_building_vendor_assignments ON building_vendor_assignments
              USING (EXISTS (SELECT 1 FROM buildings b WHERE b.id = building_vendor_assignments.building_id))
              WITH CHECK (EXISTS (SELECT 1 FROM buildings b WHERE b.id = building_vendor_assignments.building_id));

            CREATE POLICY tenant_scope_tariff_retro_adjustments ON tariff_retro_adjustments
              USING (EXISTS (SELECT 1 FROM buildings b WHERE b.id = tariff_retro_adjustments.building_id))
              WITH CHECK (EXISTS (SELECT 1 FROM buildings b WHERE b.id = tariff_retro_adjustments.building_id));

            CREATE POLICY organization_memberships_scope ON organization_memberships
              USING (organization_id = app_current_organization_id() OR user_id = app_current_user_id())
              WITH CHECK (organization_id = app_current_organization_id());

            CREATE POLICY membership_roles_scope ON membership_roles
              USING (EXISTS (SELECT 1 FROM organization_memberships m WHERE m.id = membership_roles.membership_id))
              WITH CHECK (EXISTS (SELECT 1 FROM organization_memberships m
                                  WHERE m.id = membership_roles.membership_id
                                    AND m.organization_id = app_current_organization_id()));

            CREATE POLICY building_membership_roles_scope ON building_membership_roles
              USING (EXISTS (SELECT 1 FROM building_memberships bm WHERE bm.id = building_membership_roles.building_membership_id))
              WITH CHECK (EXISTS (SELECT 1 FROM building_memberships bm
                                  JOIN organization_memberships m ON m.id = bm.organization_membership_id
                                  WHERE bm.id = building_membership_roles.building_membership_id
                                    AND m.organization_id = app_current_organization_id()));

            CREATE POLICY user_devices_scope ON user_devices
              USING (user_id = app_current_user_id())
              WITH CHECK (user_id = app_current_user_id());

            ALTER TABLE billing_disputes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE billing_disputes FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_billing_settings ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_billing_settings FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_vendor_assignments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_vendor_assignments FORCE ROW LEVEL SECURITY;
            ALTER TABLE tariff_retro_adjustments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE tariff_retro_adjustments FORCE ROW LEVEL SECURITY;
            ALTER TABLE organization_memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_memberships FORCE ROW LEVEL SECURITY;
            ALTER TABLE membership_roles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE membership_roles FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_membership_roles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_membership_roles FORCE ROW LEVEL SECURITY;
            ALTER TABLE user_devices ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_devices FORCE ROW LEVEL SECURITY;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS tenant_scope_billing_disputes ON billing_disputes;
            DROP POLICY IF EXISTS tenant_scope_building_billing_settings ON building_billing_settings;
            DROP POLICY IF EXISTS tenant_scope_building_vendor_assignments ON building_vendor_assignments;
            DROP POLICY IF EXISTS tenant_scope_tariff_retro_adjustments ON tariff_retro_adjustments;
            DROP POLICY IF EXISTS organization_memberships_scope ON organization_memberships;
            DROP POLICY IF EXISTS membership_roles_scope ON membership_roles;
            DROP POLICY IF EXISTS building_membership_roles_scope ON building_membership_roles;
            DROP POLICY IF EXISTS user_devices_scope ON user_devices;
            ALTER TABLE billing_disputes NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE billing_disputes DISABLE ROW LEVEL SECURITY;
            ALTER TABLE building_billing_settings NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_billing_settings DISABLE ROW LEVEL SECURITY;
            ALTER TABLE building_vendor_assignments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_vendor_assignments DISABLE ROW LEVEL SECURITY;
            ALTER TABLE tariff_retro_adjustments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE tariff_retro_adjustments DISABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_memberships NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE organization_memberships DISABLE ROW LEVEL SECURITY;
            ALTER TABLE membership_roles NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE membership_roles DISABLE ROW LEVEL SECURITY;
            ALTER TABLE building_membership_roles NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_membership_roles DISABLE ROW LEVEL SECURITY;
            ALTER TABLE user_devices NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE user_devices DISABLE ROW LEVEL SECURITY;
            SQL);
    }
};
