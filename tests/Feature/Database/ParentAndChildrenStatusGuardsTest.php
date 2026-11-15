<?php

/**
 * The generic parent/children status rules (design 08 §2.3c, V7.19, D46) on
 * the fixture tables lifecycle_test_batches and lifecycle_test_batch_items:
 *
 * - refuseNewChildrenWhenParentClosed(): no new items in a closed or
 *   cancelled batch
 * - requireParentStatus(): an item is accepted only while its batch is open
 * - requireChildrenStatus(): a batch is cancelled only when its items are void
 */

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A new fixture batch in the given status.
 */
function fixtureBatch(string $status = 'draft'): int
{
    $id = DB::table('lifecycle_test_batches')->insertGetId(['name' => 'Batch']);
    DB::table('lifecycle_test_batches')->where('id', $id)->update(['status' => $status]);

    return $id;
}

/**
 * A new pending item of the batch.
 */
function fixtureBatchItem(int $batchId): int
{
    return DB::table('lifecycle_test_batch_items')->insertGetId(['batch_id' => $batchId, 'amount' => 10]);
}

describe('new children of a closed parent', function () {
    it('can be added while the parent is open', function () {
        // Arrange
        $batchId = fixtureBatch('open');

        // Act
        fixtureBatchItem($batchId);

        // Assert
        expect(DB::table('lifecycle_test_batch_items')->where('batch_id', $batchId)->count())->toBe(1);
    });

    it('cannot be added once the parent is closed', function (string $status) {
        // Arrange
        $batchId = fixtureBatch($status);

        // Act & Assert
        expect(fn () => fixtureBatchItem($batchId))
            ->toThrow(QueryException::class, "lifecycle_test_batches {$batchId} is {$status}: its lifecycle_test_batch_items can no longer change");
    })->with(['closed', 'cancelled']);

    it('cannot be moved in from another parent', function () {
        // Arrange
        $itemId = fixtureBatchItem(fixtureBatch('open'));
        $closedId = fixtureBatch('closed');

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['batch_id' => $closedId]))
            ->toThrow(QueryException::class, 'can no longer change');
    });

    it('leave the existing children of a closed parent changeable', function () {
        // Arrange
        $batchId = fixtureBatch('open');
        $itemId = fixtureBatchItem($batchId);
        DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'closed']);

        // Act
        DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['amount' => 12]);

        // Assert
        expect((float) DB::table('lifecycle_test_batch_items')->where('id', $itemId)->value('amount'))->toBe(12.0);
    });
});

describe('a child that needs its parent in a status', function () {
    it('may be written in other statuses whatever the parent is', function () {
        // Arrange
        $batchId = fixtureBatch('draft');

        // Act
        $itemId = fixtureBatchItem($batchId);

        // Assert
        expect(DB::table('lifecycle_test_batch_items')->where('id', $itemId)->value('status'))->toBe('pending');
    });

    it('is refused in the guarded status while the parent is not in a required one', function () {
        // Arrange
        $batchId = fixtureBatch('draft');
        $itemId = fixtureBatchItem($batchId);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'accepted']))
            ->toThrow(QueryException::class, "lifecycle_test_batches {$batchId} is draft: lifecycle_test_batch_items in status accepted needs it to be open");
    });

    it('is refused when created in the guarded status', function () {
        // Arrange
        $batchId = fixtureBatch('draft');

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_batch_items')->insert(['batch_id' => $batchId, 'amount' => 10, 'status' => 'accepted']))
            ->toThrow(QueryException::class, 'needs it to be open');
    });

    it('is accepted while the parent is in a required status', function () {
        // Arrange
        $itemId = fixtureBatchItem(fixtureBatch('open'));

        // Act
        DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'accepted']);

        // Assert
        expect(DB::table('lifecycle_test_batch_items')->where('id', $itemId)->value('status'))->toBe('accepted');
    });

    it('is not checked again when other columns change', function () {
        // Arrange
        $batchId = fixtureBatch('open');
        $itemId = fixtureBatchItem($batchId);
        DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'accepted']);
        DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'draft']);

        // Act
        DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['amount' => 15, 'status' => 'accepted']);

        // Assert
        expect((float) DB::table('lifecycle_test_batch_items')->where('id', $itemId)->value('amount'))->toBe(15.0);
    });

    it('is checked again when it moves to another parent', function () {
        // Arrange
        $itemId = fixtureBatchItem(fixtureBatch('open'));
        DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'accepted']);
        $draftId = fixtureBatch('draft');

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_batch_items')->where('id', $itemId)->update(['batch_id' => $draftId]))
            ->toThrow(QueryException::class, 'needs it to be open');
    });
});

describe('a parent that needs its children in a status', function () {
    it('is refused while a child is in another status', function () {
        // Arrange
        $batchId = fixtureBatch('open');
        fixtureBatchItem($batchId);
        DB::table('lifecycle_test_batch_items')->where('batch_id', $batchId)->update(['status' => 'void']);
        fixtureBatchItem($batchId);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'cancelled']))
            ->toThrow(QueryException::class, "lifecycle_test_batches {$batchId} cannot become cancelled: 1 of its lifecycle_test_batch_items are pending (must be void)");
    });

    it('is allowed once every child is in an allowed status', function () {
        // Arrange
        $batchId = fixtureBatch('open');
        fixtureBatchItem($batchId);
        DB::table('lifecycle_test_batch_items')->where('batch_id', $batchId)->update(['status' => 'void']);

        // Act
        DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'cancelled']);

        // Assert
        expect(DB::table('lifecycle_test_batches')->where('id', $batchId)->value('status'))->toBe('cancelled');
    });

    it('ignores children for other statuses of the parent', function () {
        // Arrange
        $batchId = fixtureBatch('open');
        fixtureBatchItem($batchId);

        // Act
        DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'closed']);

        // Assert
        expect(DB::table('lifecycle_test_batches')->where('id', $batchId)->value('status'))->toBe('closed');
    });

    it('ignores hidden children', function () {
        // Arrange
        $batchId = fixtureBatch('open');
        DB::table('lifecycle_test_batch_items')->where('id', fixtureBatchItem($batchId))->delete();

        // Act
        DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'cancelled']);

        // Assert
        expect(DB::table('lifecycle_test_batches')->where('id', $batchId)->value('status'))->toBe('cancelled');
    });

    it('ignores children of other parents', function () {
        // Arrange
        $batchId = fixtureBatch('open');
        fixtureBatchItem(fixtureBatch('open'));

        // Act
        DB::table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'cancelled']);

        // Assert
        expect(DB::table('lifecycle_test_batches')->where('id', $batchId)->value('status'))->toBe('cancelled');
    });
});

describe('maintenance override', function () {
    beforeEach(function () {
        $this->operator = DB::connection('pgsql_maintenance');
        $this->operator->beginTransaction();
        $this->batchId = $this->operator->table('lifecycle_test_batches')->insertGetId(['name' => 'Batch']);
        $this->itemId = $this->operator->table('lifecycle_test_batch_items')->insertGetId(['batch_id' => $this->batchId, 'amount' => 10]);
        $this->operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['Audit request #31: batch fixed by hand'],
        );
    });

    afterEach(function () {
        $this->operator->rollBack();
    });

    it('may break each rule', function (Closure $write) {
        // Act
        $write($this->operator, $this->batchId, $this->itemId);

        // Assert
        expect(true)->toBeTrue();
    })->with([
        'child needs parent status' => fn ($db, $batchId, $itemId) => $db->table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'accepted']),
        'parent needs children status' => fn ($db, $batchId) => $db->table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'cancelled']),
        'no new children' => function ($db, $batchId) {
            $db->table('lifecycle_test_batches')->where('id', $batchId)->update(['status' => 'closed']);
            $db->table('lifecycle_test_batch_items')->insert(['batch_id' => $batchId, 'amount' => 5]);
        },
    ]);
});

describe('declaring the rules', function () {
    // The helpers need the owner role; their changes are rolled back after each test.
    beforeEach(function () {
        $this->owner = DB::connection('pgsql_migrations');
        $this->owner->beginTransaction();
    });

    afterEach(function () {
        $this->owner->rollBack();
    });

    it('creates one named trigger per rule', function (string $table, string $trigger) {
        // Act
        $count = $this->owner->table('pg_trigger')->where('tgname', $trigger)->count();

        // Assert
        expect($count)->toBe(1);
    })->with([
        ['lifecycle_test_batch_items', 'trg_lifecycle_test_batch_items_closed_parent_insert'],
        ['lifecycle_test_batch_items', 'trg_lifecycle_test_batch_items_parent_acceptance'],
        ['lifecycle_test_batches', 'trg_lifecycle_test_batches_children_cancel'],
    ]);

    it('replaces a rule when it is declared again', function () {
        // Arrange
        LifecycleSchema::requireParentStatus('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id',
            parentStatuses: ['open', 'draft'], whenStatuses: ['accepted'], name: 'acceptance', connection: 'pgsql_migrations');
        $batchId = $this->owner->table('lifecycle_test_batches')->insertGetId(['name' => 'Batch']);
        $itemId = $this->owner->table('lifecycle_test_batch_items')->insertGetId(['batch_id' => $batchId, 'amount' => 10]);

        // Act
        $this->owner->table('lifecycle_test_batch_items')->where('id', $itemId)->update(['status' => 'accepted']);

        // Assert
        expect($this->owner->table('lifecycle_test_batch_items')->where('id', $itemId)->value('status'))->toBe('accepted');
    });

    it('refuses what it cannot check', function (Closure $declare, string $message) {
        // Act & Assert
        expect($declare)->toThrow(QueryException::class, $message);
    })->with([
        'unknown parent status' => [fn () => LifecycleSchema::requireParentStatus('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', ['finished'], connection: 'pgsql_migrations'), 'Unknown status of lifecycle_test_batches: finished'],
        'unknown child status' => [fn () => LifecycleSchema::requireChildrenStatus('lifecycle_test_batches', 'lifecycle_test_batch_items', 'batch_id', ['cancelled'], ['gone'], connection: 'pgsql_migrations'), 'Unknown status of lifecycle_test_batch_items: gone'],
        'unregistered table' => [fn () => LifecycleSchema::refuseNewChildrenWhenParentClosed('lifecycle_test_batch_items', 'lifecycle_test_entries', 'batch_id', ['closed'], connection: 'pgsql_migrations'), 'Table lifecycle_test_entries is not registered'],
        'missing column' => [fn () => LifecycleSchema::requireParentStatus('lifecycle_test_batch_items', 'lifecycle_test_batches', 'group_id', ['open'], connection: 'pgsql_migrations'), 'Column lifecycle_test_batch_items.group_id does not exist'],
        'no parent status' => [fn () => LifecycleSchema::requireParentStatus('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', [], connection: 'pgsql_migrations'), 'Name at least one status'],
        'no closing status' => [fn () => LifecycleSchema::refuseNewChildrenWhenParentClosed('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', [], connection: 'pgsql_migrations'), 'Name at least one status'],
        'no children status' => [fn () => LifecycleSchema::requireChildrenStatus('lifecycle_test_batches', 'lifecycle_test_batch_items', 'batch_id', ['cancelled'], [], connection: 'pgsql_migrations'), 'Name the statuses'],
        'bad rule name' => [fn () => LifecycleSchema::requireParentStatus('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', ['open'], name: 'Bad-Name', connection: 'pgsql_migrations'), 'must be lower_snake_case'],
    ]);

    it('cannot be called by the runtime role', function (string $call) {
        // Act & Assert
        expect(fn () => DB::select($call))->toThrow(QueryException::class, 'permission denied for function');
    })->with([
        "select lifecycle_refuse_children_of_closed_parent('lifecycle_test_batch_items', 'lifecycle_test_batches', 'batch_id', array['draft'])",
        "select lifecycle_require_parent_status('lifecycle_test_batch_items', 'x', 'lifecycle_test_batches', 'batch_id', array['draft'], array[]::text[])",
        "select lifecycle_require_children_status('lifecycle_test_batches', 'x', 'lifecycle_test_batch_items', 'batch_id', array['cancelled'], array['void'])",
    ]);
});
