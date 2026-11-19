<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setup tasks say who did them and stay with the building's people (owner
 * decision D50, design V7.23, group 13 question 8):
 *
 * - completed ⇔ completion time (and so the person, by the existing pair
 *   check).
 * - user_can_access_building(user, building): the user has an active
 *   membership of an organization with access to the building
 *   (organization_can_access_building()). Reusable wherever a person is
 *   named for a building. A task's assignee must pass it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE building_setup_tasks
              ADD CONSTRAINT ck_building_setup_tasks_completed CHECK ((status = 'completed') = (completed_at IS NOT NULL));

            CREATE OR REPLACE FUNCTION user_can_access_building(p_user_id BIGINT, p_building_id BIGINT)
            RETURNS BOOLEAN LANGUAGE sql STABLE AS $$
              SELECT EXISTS (
                SELECT 1 FROM organization_memberships m
                WHERE m.user_id = p_user_id AND m.status = 'active' AND NOT m.is_deleted
                  AND organization_can_access_building(m.organization_id, p_building_id))
            $$;

            CREATE OR REPLACE FUNCTION validate_setup_task_assignee() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.assigned_user_id IS NOT NULL AND NOT user_can_access_building(NEW.assigned_user_id, NEW.building_id) THEN
                RAISE EXCEPTION 'User % is not a member of an organization with access to building %',
                  NEW.assigned_user_id, NEW.building_id;
              END IF;
              RETURN NEW;
            END; $$;

            CREATE OR REPLACE TRIGGER trg_building_setup_tasks_assignee
            BEFORE INSERT OR UPDATE OF assigned_user_id, building_id ON building_setup_tasks
            FOR EACH ROW EXECUTE FUNCTION validate_setup_task_assignee();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_building_setup_tasks_assignee ON building_setup_tasks;
            DROP FUNCTION IF EXISTS validate_setup_task_assignee();
            DROP FUNCTION IF EXISTS user_can_access_building(BIGINT, BIGINT);
            ALTER TABLE building_setup_tasks DROP CONSTRAINT ck_building_setup_tasks_completed;
            SQL);
    }
};
