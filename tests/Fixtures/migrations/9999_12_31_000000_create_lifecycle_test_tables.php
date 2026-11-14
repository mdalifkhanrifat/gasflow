<?php

use App\Enums\Lifecycle\HardDeletePolicy;
use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Test-only tables (loaded by RefreshPostgresDatabase, never in production).
 * Each one joins the lifecycle standard with a different policy, through the
 * same LifecycleSchema helper the real migrations use, so the generic
 * triggers can be tested without depending on any domain table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE lifecycle_test_items (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              name TEXT NOT NULL,
              secret TEXT
            );
            CREATE TABLE lifecycle_test_ledgers (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              amount NUMERIC(14,2) NOT NULL
            );
            CREATE TABLE lifecycle_test_periods (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              name TEXT NOT NULL,
              budget NUMERIC(14,2) NOT NULL DEFAULT 0,
              note TEXT
            );
            -- child of a period, frozen once the period is locked
            CREATE TABLE lifecycle_test_period_lines (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              period_id BIGINT NOT NULL REFERENCES lifecycle_test_periods(id),
              amount NUMERIC(14,2) NOT NULL
            );
            CREATE TABLE lifecycle_test_codes (
              code VARCHAR(32) PRIMARY KEY,
              name TEXT NOT NULL
            );
            CREATE TABLE lifecycle_test_links (
              left_id BIGINT NOT NULL,
              right_id BIGINT NOT NULL,
              PRIMARY KEY (left_id, right_id)
            );
            -- not in the lifecycle standard; its rule is checked only at COMMIT,
            -- like the ledger balance checks
            CREATE TABLE lifecycle_test_entries (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              reference TEXT NOT NULL,
              CONSTRAINT uq_lifecycle_test_entries_reference UNIQUE (reference) DEFERRABLE INITIALLY DEFERRED
            );
            SQL);

        // master record, generic "*" statuses, soft delete allowed, one redacted column
        LifecycleSchema::attach('lifecycle_test_items', module: 'testing', class: RecordClass::Master);
        DB::update("update lifecycle_entities set redacted_columns = '{secret}' where entity_name = 'lifecycle_test_items'");

        // financial record: protected, the app may never delete or hide it
        LifecycleSchema::attach(
            'lifecycle_test_ledgers',
            module: 'testing',
            class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected,
        );

        // workflow record with its own statuses and enforced transitions
        LifecycleSchema::attach(
            'lifecycle_test_periods',
            module: 'testing',
            class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected,
            enforceTransitions: true,
            defaultStatus: 'open',
        );
        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order)
            VALUES ('lifecycle_test_periods', 'open',       'Open',       'info',  true,  true,  false, 10),
                   ('lifecycle_test_periods', 'collecting', 'Collecting', 'info',  true,  false, false, 20),
                   ('lifecycle_test_periods', 'locked',     'Locked',     'muted', false, false, true,  30);
            INSERT INTO status_transitions(entity_name, from_code, to_code)
            VALUES ('lifecycle_test_periods', 'open', 'collecting'),
                   ('lifecycle_test_periods', 'collecting', 'locked');
            SQL);

        // a period's name and budget are fixed once it leaves "open"; note stays free
        LifecycleSchema::freezeColumns('lifecycle_test_periods', ['name', 'budget'], editableStatuses: ['open']);

        // master record whose rows freeze once their period is locked
        LifecycleSchema::attach('lifecycle_test_period_lines', module: 'testing', class: RecordClass::Master);
        LifecycleSchema::freezeWhenParentClosed('lifecycle_test_period_lines', parent: 'lifecycle_test_periods',
            foreignKey: 'period_id', closedStatuses: ['locked']);

        // catalogue with a text primary key (like payment_method_catalog)
        LifecycleSchema::attach('lifecycle_test_codes', module: 'testing', class: RecordClass::Configuration);

        // pivot with a composite primary key; hard delete allowed
        LifecycleSchema::attach(
            'lifecycle_test_links',
            module: 'testing',
            class: RecordClass::Pivot,
            hardDelete: HardDeletePolicy::Allowed,
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS lifecycle_test_entries, lifecycle_test_links, lifecycle_test_codes, lifecycle_test_period_lines,
                                 lifecycle_test_periods,
                                 lifecycle_test_ledgers, lifecycle_test_items;
            DELETE FROM status_transitions WHERE entity_name LIKE 'lifecycle_test_%';
            DELETE FROM status_definitions WHERE entity_name LIKE 'lifecycle_test_%';
            DELETE FROM lifecycle_entities WHERE entity_name LIKE 'lifecycle_test_%';
            SQL);
    }
};
