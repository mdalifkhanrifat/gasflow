<?php

/**
 * LifecycleSchema::attach(), the migration helper that joins a table to the
 * record lifecycle (calls the PostgreSQL function lifecycle_attach()).
 */

use App\Enums\Lifecycle\RecordClass;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function columnsOf(ConnectionInterface $connection, string $table): array
{
    return $connection->table('information_schema.columns')
        ->where('table_name', $table)
        ->orderBy('ordinal_position')
        ->pluck('column_name')
        ->all();
}

describe('registered policy', function () {
    it('stores the policy given to attach()', function (string $table, array $expected) {
        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([
            'module' => $entity->module,
            'record_class' => $entity->record_class,
            'soft_delete_policy' => $entity->soft_delete_policy,
            'hard_delete_policy' => $entity->hard_delete_policy,
            'enforce_transitions' => $entity->enforce_transitions,
            'default_status' => $entity->default_status,
        ])->toBe($expected);
    })->with([
        'master defaults' => ['lifecycle_test_items', [
            'module' => 'testing', 'record_class' => 'master', 'soft_delete_policy' => 'allowed',
            'hard_delete_policy' => 'forbidden', 'enforce_transitions' => false, 'default_status' => 'active',
        ]],
        'protected financial' => ['lifecycle_test_ledgers', [
            'module' => 'testing', 'record_class' => 'financial', 'soft_delete_policy' => 'protected',
            'hard_delete_policy' => 'forbidden', 'enforce_transitions' => false, 'default_status' => 'active',
        ]],
        'workflow with transitions' => ['lifecycle_test_periods', [
            'module' => 'testing', 'record_class' => 'workflow', 'soft_delete_policy' => 'protected',
            'hard_delete_policy' => 'forbidden', 'enforce_transitions' => true, 'default_status' => 'open',
        ]],
        'pivot with hard delete' => ['lifecycle_test_links', [
            'module' => 'testing', 'record_class' => 'pivot', 'soft_delete_policy' => 'allowed',
            'hard_delete_policy' => 'allowed', 'enforce_transitions' => false, 'default_status' => 'active',
        ]],
    ]);
});

describe('attaching a new table', function () {
    // attach() needs the owner role; its changes are rolled back after each test.
    beforeEach(function () {
        $this->owner = DB::connection('pgsql_migrations');
        $this->owner->beginTransaction();
        $this->owner->statement('create table lifecycle_test_new (id bigint generated always as identity primary key, name text)');
    });

    afterEach(function () {
        $this->owner->rollBack();
    });

    it('adds the lifecycle columns with one call', function () {
        // Act
        LifecycleSchema::attach('lifecycle_test_new', module: 'testing', class: RecordClass::Master, connection: 'pgsql_migrations');

        // Assert
        expect(columnsOf($this->owner, 'lifecycle_test_new'))->toBe([
            'id', 'name', 'status', 'is_active', 'is_deleted', 'deleted_at', 'created_at', 'updated_at',
            'created_by', 'updated_by', 'created_session_id', 'updated_session_id',
        ]);
    });

    it('can run again without duplicating anything', function () {
        // Arrange
        LifecycleSchema::attach('lifecycle_test_new', module: 'testing', class: RecordClass::Master, connection: 'pgsql_migrations');

        // Act
        LifecycleSchema::attach('lifecycle_test_new', module: 'testing', class: RecordClass::Configuration, connection: 'pgsql_migrations');

        // Assert
        $registry = $this->owner->table('lifecycle_entities')->where('entity_name', 'lifecycle_test_new')->get();
        $triggers = $this->owner->table('information_schema.triggers')
            ->where('event_object_table', 'lifecycle_test_new')
            ->distinct()
            ->count('trigger_name');
        expect($registry)->toHaveCount(1)
            ->and($registry->first()->record_class)->toBe('configuration')
            ->and($triggers)->toBe(5);
    });
});
