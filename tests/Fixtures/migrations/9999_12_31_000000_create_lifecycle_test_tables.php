<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Test-only tables (loaded by RefreshPostgresDatabase, never in production).
 * Each one joins the lifecycle standard with a different policy, so the generic
 * triggers can be tested without depending on any domain table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- master record, generic "*" statuses, soft delete allowed, one redacted column
            CREATE TABLE lifecycle_test_items (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              name TEXT NOT NULL,
              secret TEXT
            );
            SELECT lifecycle_attach('lifecycle_test_items', 'testing', 'master');
            UPDATE lifecycle_entities SET redacted_columns = '{secret}'
             WHERE entity_name = 'lifecycle_test_items';

            -- financial record: protected, the app may never delete or hide it
            CREATE TABLE lifecycle_test_ledgers (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              amount NUMERIC(14,2) NOT NULL
            );
            SELECT lifecycle_attach('lifecycle_test_ledgers', 'testing', 'financial', 'protected');

            -- workflow record with its own statuses and enforced transitions
            CREATE TABLE lifecycle_test_periods (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              name TEXT NOT NULL
            );
            SELECT lifecycle_attach('lifecycle_test_periods', 'testing', 'workflow',
                                    'protected', 'forbidden', true, 'open');
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order)
            VALUES ('lifecycle_test_periods', 'open',       'Open',       'info',  true,  true,  false, 10),
                   ('lifecycle_test_periods', 'collecting', 'Collecting', 'info',  true,  false, false, 20),
                   ('lifecycle_test_periods', 'locked',     'Locked',     'muted', false, false, true,  30);
            INSERT INTO status_transitions(entity_name, from_code, to_code)
            VALUES ('lifecycle_test_periods', 'open', 'collecting'),
                   ('lifecycle_test_periods', 'collecting', 'locked');

            -- pivot with a composite primary key; hard delete allowed
            CREATE TABLE lifecycle_test_links (
              left_id BIGINT NOT NULL,
              right_id BIGINT NOT NULL,
              PRIMARY KEY (left_id, right_id)
            );
            SELECT lifecycle_attach('lifecycle_test_links', 'testing', 'pivot', 'allowed', 'allowed');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS lifecycle_test_links, lifecycle_test_periods,
                                 lifecycle_test_ledgers, lifecycle_test_items;
            DELETE FROM status_transitions WHERE entity_name LIKE 'lifecycle_test_%';
            DELETE FROM status_definitions WHERE entity_name LIKE 'lifecycle_test_%';
            DELETE FROM lifecycle_entities WHERE entity_name LIKE 'lifecycle_test_%';
            SQL);
    }
};
