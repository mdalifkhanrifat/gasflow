<?php

/**
 * The generic rule freezeColumnsWithParent() (design 08 §2.3d, V7.23, D50) on
 * the fixture tables: an item's amount stops changing once its batch is
 * cancelled.
 */

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A void item of 10 in a batch that is then cancelled.
 *
 * @return array{batch: int, item: int}
 */
function cancelledBatchWithItem(): array
{
    $batchId = DB::table('lifecycle_test_batches')->insertGetId(['name' => 'Batch']);
    $itemId = DB::table('lifecycle_test_batch_items')->insertGetId(['batch_id' => $batchId, 'amount' => 10]);
    DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'void']);
    DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'cancelled']);

    return ['batch' => $batchId, 'item' => $itemId];
}

describe('frozen columns of a child', function () {
    it('change while the parent is not closed', function () {
        // Arrange
        $batchId = DB::table('lifecycle_test_batches')->insertGetId(['name' => 'Batch']);
        $itemId = DB::table('lifecycle_test_batch_items')->insertGetId(['batch_id' => $batchId, 'amount' => 10]);

        // Act
        DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['amount' => 12]);

        // Assert
        expect((float) DB::table('lifecycle_test_batch_items')->where('id', $itemId)->value('amount'))->toBe(12.0);
    });

    it('cannot change once the parent is closed', function () {
        // Arrange
        ['batch' => $batchId, 'item' => $itemId] = cancelledBatchWithItem();

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['amount' => 12]))
            ->toThrow(QueryException::class, "lifecycle_test_batches {$batchId} is cancelled: amount of lifecycle_test_batch_items {$itemId} can no longer change");
    });

    it('may be written again with the same value', function () {
        // Arrange
        ['item' => $itemId] = cancelledBatchWithItem();

        // Act
        DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['amount' => 10]);

        // Assert
        expect((float) DB::table('lifecycle_test_batch_items')->where('id', $itemId)->value('amount'))->toBe(10.0);
    });

    it('may be corrected through the maintenance override', function () {
        // Arrange
        $operator = DB::connection('pgsql_maintenance');
        $operator->beginTransaction();
        $batchId = $operator->table('lifecycle_test_batches')->insertGetId(['name' => 'Batch']);
        $itemId = $operator->table('lifecycle_test_batch_items')->insertGetId(['batch_id' => $batchId, 'amount' => 10]);
        $operator->table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'void']);
        $operator->table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'cancelled']);
        $operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['Audit request #32: amount fixed by hand'],
        );

        // Act
        $operator->table('lifecycle_test_batch_items')->where('id', $itemId)->update(['amount' => 12]);

        // Assert
        $amount = (float) $operator->table('lifecycle_test_batch_items')->where('id', $itemId)->value('amount');
        $operator->rollBack();
        expect($amount)->toBe(12.0);
    });
});

describe('declaring the rule', function () {
    // The helper needs the owner role; its changes are rolled back after each test.
    beforeEach(function () {
        $this->owner = DB::connection('pgsql_migrations');
        $this->owner->beginTransaction();
    });

    afterEach(function () {
        $this->owner->rollBack();
    });

    it('creates one named trigger', function () {
        // Act
        $count = $this->owner->table('pg_trigger')->where('tgname', 'trg_lifecycle_test_batch_items_parent_freeze_amount')->count();

        // Assert
        expect($count)->toBe(1);
    });

    it('replaces the rule when called again with the same name', function () {
        // Arrange
        LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id',
            ['amount'], ['closed'], name: 'amount', connection: 'pgsql_migrations');

        // Act
        $arguments = $this->owner->selectOne(
            "select encode(tgargs, 'escape') as args from pg_trigger where tgname = 'trg_lifecycle_test_batch_items_parent_freeze_amount'",
        )->args;

        // Assert
        expect($arguments)->toContain('closed')->not->toContain('cancelled');
    });

    it('refuses what it cannot check', function (Closure $declare, string $message) {
        // Act & Assert
        expect($declare)->toThrow(QueryException::class, $message);
    })->with([
        'unknown parent status' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', ['amount'], ['finished'], connection: 'pgsql_migrations'), 'Unknown status of lifecycle_test_batches: finished'],
        'unregistered parent' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_entries', 'batch_id', ['amount'], ['closed'], connection: 'pgsql_migrations'), 'Parent table lifecycle_test_entries is not registered'],
        'unknown column' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', ['price'], ['closed'], connection: 'pgsql_migrations'), 'Unknown column of lifecycle_test_batch_items: price'],
        'missing foreign key' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'group_id', ['amount'], ['closed'], connection: 'pgsql_migrations'), 'Unknown column of lifecycle_test_batch_items: group_id'],
        'lifecycle column' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', ['status'], ['closed'], connection: 'pgsql_migrations'), 'Lifecycle columns follow the lifecycle rules'],
        'no column' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', [], ['closed'], connection: 'pgsql_migrations'), 'Name the columns'],
        'no status' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', ['amount'], [], connection: 'pgsql_migrations'), 'Name the columns'],
        'bad rule name' => [fn () => LifecycleSchema::freezeColumnsWithParent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', ['amount'], ['closed'], name: 'Bad-Name', connection: 'pgsql_migrations'), 'must be lower_snake_case'],
    ]);

    it('cannot be called by the runtime role', function () {
        // Act & Assert
        expect(fn () => DB::select("select lifecycle_freeze_columns_with_parent('lifecycle_test_batch_items', 'x', 'lifecycle_test_batches', 'batch_id', array['amount'], array['closed'])"))
            ->toThrow(QueryException::class, 'permission denied for function');
    });
});
