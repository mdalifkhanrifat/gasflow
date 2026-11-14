<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One rule for "may this organization work in this building?" (design 02 §2,
 * V7.15): the organization owns the building, or holds an active access grant
 * that is in force today.
 *
 * Every check that ties an organization to a building calls this function, so
 * the rule changes in one place: building memberships (here), flat occupancies
 * and attachments (next migrations), and later the row-level security policies
 * of group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION organization_can_access_building(p_organization_id BIGINT, p_building_id BIGINT)
            RETURNS BOOLEAN LANGUAGE sql STABLE AS $$
              SELECT EXISTS (
                       SELECT 1 FROM buildings b
                       WHERE b.id = p_building_id AND b.tenant_organization_id = p_organization_id)
                  OR EXISTS (
                       SELECT 1 FROM building_access_grants g
                       WHERE g.building_id = p_building_id
                         AND g.grantee_organization_id = p_organization_id
                         AND g.status = 'active'
                         AND g.valid_from <= CURRENT_DATE
                         AND (g.valid_to IS NULL OR g.valid_to >= CURRENT_DATE))
            $$;

            CREATE OR REPLACE FUNCTION validate_building_membership_access() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE membership_org BIGINT;
            BEGIN
              SELECT organization_id INTO membership_org
                FROM organization_memberships WHERE id = NEW.organization_membership_id AND status = 'active';
              IF membership_org IS NULL THEN
                RAISE EXCEPTION 'An active organization membership is required';
              END IF;
              IF NOT EXISTS (SELECT 1 FROM buildings WHERE id = NEW.building_id) THEN
                RAISE EXCEPTION 'Building does not exist';
              END IF;
              IF NOT organization_can_access_building(membership_org, NEW.building_id) THEN
                RAISE EXCEPTION 'Organization has no active grant for this building';
              END IF;
              RETURN NEW;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        // Restore the trigger function of 2026_11_14_000006, then drop the shared check.
        DB::unprepared(<<<'SQL'
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

            DROP FUNCTION IF EXISTS organization_can_access_building(BIGINT, BIGINT);
            SQL);
    }
};
