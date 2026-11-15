<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Test-only parent and child tables for the generic parent/children status
 * rules (loaded by RefreshPostgresDatabase, never in production):
 *
 * - no new items once their batch is closed (existing items still change)
 * - an item may only be accepted while its batch is open
 * - a batch may only be cancelled once all its items are void
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE lifecycle_test_batches (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              name TEXT NOT NULL
            );
            CREATE TABLE lifecycle_test_batch_items (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              batch_id BIGINT NOT NULL REFERENCES lifecycle_test_batches(id),
              amount NUMERIC(14,2) NOT NULL
            );
            SQL);

        LifecycleSchema::attach('lifecycle_test_batches', module: 'testing', class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'draft');
        LifecycleSchema::attach('lifecycle_test_batch_items', module: 'testing', class: RecordClass::Master, defaultStatus: 'pending');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order)
            VALUES ('lifecycle_test_batches', 'draft',     'Draft',     'neutral', NULL, true,  false, 10),
                   ('lifecycle_test_batches', 'open',      'Open',      'info',    NULL, false, false, 20),
                   ('lifecycle_test_batches', 'closed',    'Closed',    'muted',   NULL, false, true,  30),
                   ('lifecycle_test_batches', 'cancelled', 'Cancelled', 'muted',   NULL, false, true,  40),
                   ('lifecycle_test_batch_items', 'pending',  'Pending',  'warning', NULL, true,  false, 10),
                   ('lifecycle_test_batch_items', 'accepted', 'Accepted', 'success', NULL, true,  false, 20),
                   ('lifecycle_test_batch_items', 'void',     'Void',     'muted',   NULL, false, true,  30);
            SQL);

        LifecycleSchema::refuseNewChildrenWhenParentClosed('lifecycle_test_batch_items', parent: 'lifecycle_test_batches',
            foreignKey: 'batch_id', closedStatuses: ['closed', 'cancelled']);
        LifecycleSchema::requireParentStatus('lifecycle_test_batch_items', parent: 'lifecycle_test_batches',
            foreignKey: 'batch_id', parentStatuses: ['open'], whenStatuses: ['accepted'], name: 'acceptance');
        LifecycleSchema::requireChildrenStatus('lifecycle_test_batches', child: 'lifecycle_test_batch_items',
            foreignKey: 'batch_id', whenStatuses: ['cancelled'], childStatuses: ['void'], name: 'cancel');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS lifecycle_test_batch_items, lifecycle_test_batches;
            DELETE FROM status_definitions WHERE entity_name IN ('lifecycle_test_batches', 'lifecycle_test_batch_items');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('lifecycle_test_batches', 'lifecycle_test_batch_items');
            SQL);
    }
};
