<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The roles of the platform's actors (owner decision D53, design V7.26,
 * group 16 question 2; brief §3, design 01 §2), one per scope they work in.
 * The flat owner or tenant uses the customer portal and has no role. Which
 * permissions each role holds (role_permissions) comes with the Phase 4
 * Policies and their RBAC matrix tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO roles(code, name, scope) VALUES
              ('platform.super_admin',      'Super admin',        'platform'),
              ('organization.admin',        'Organization admin', 'organization'),
              ('organization.vendor_staff', 'Gas vendor staff',   'organization'),
              ('building.manager',          'Building manager',   'building'),
              ('building.caretaker',        'Caretaker',          'building');
            SQL);
    }

    /**
     * The lifecycle guard turns a DELETE into a soft delete, which would keep
     * the codes taken; the owner switches it off to remove the seed for good.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE roles DISABLE TRIGGER trg_00_lifecycle_guard;
            DELETE FROM roles WHERE code IN ('platform.super_admin', 'organization.admin', 'organization.vendor_staff',
                                             'building.manager', 'building.caretaker');
            ALTER TABLE roles ENABLE TRIGGER trg_00_lifecycle_guard;
            SQL);
    }
};
