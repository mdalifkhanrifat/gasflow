<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installation statuses live only in status_definitions (design V7.6), like
 * every other table: the lifecycle trigger already refuses an unknown status,
 * and a CHECK list would stop a new status from being added later.
 * 2026_11_19_000062 added such CHECKs by mistake; they are dropped here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE installation_agreements DROP CONSTRAINT ck_installation_agreements_status;
            ALTER TABLE installation_installments DROP CONSTRAINT ck_installation_installments_status;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE installation_agreements ADD CONSTRAINT ck_installation_agreements_status
              CHECK (status IN ('draft','active','completed','cancelled','defaulted'));
            ALTER TABLE installation_installments ADD CONSTRAINT ck_installation_installments_status
              CHECK (status IN ('scheduled','invoiced','partially_paid','paid','waived','overdue'));
            SQL);
    }
};
