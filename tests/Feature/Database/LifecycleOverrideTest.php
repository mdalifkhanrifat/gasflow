<?php

/**
 * The maintenance override at database level (08 §8), on the operator login
 * (a gasflow_maintenance member) and the test-only fixture tables.
 * The gasflow:maintenance command itself is tested in MaintenanceOverrideTest.
 *
 * Ported from gasflow-design-v7/tests/override-tests.sql (generic parts; the
 * ledger parts come with the ledger migration group).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->operator = DB::connection('pgsql_maintenance');
    $this->operator->beginTransaction();
});

afterEach(function () {
    $this->operator->rollBack();
});

/**
 * Switch the override on for the rest of the test transaction.
 */
function overrideOn(object $test, string $reason = 'Audit request #17: duplicate entry'): void
{
    $test->operator->select(
        "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
        [$reason],
    );
}

describe('maintenance override', function () {
    it('is needed even by a maintenance member to hide a protected row', function () {
        // Arrange
        $id = $this->operator->table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);

        // Act & Assert
        expect(fn () => $this->operator->table('lifecycle_test_ledgers')->where('id', $id)->delete())
            ->toThrow(QueryException::class, 'Records in lifecycle_test_ledgers are protected');
    });

    it('is refused without a reason', function () {
        // Arrange
        $id = $this->operator->table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);
        overrideOn($this, reason: '');

        // Act & Assert
        expect(fn () => $this->operator->table('lifecycle_test_ledgers')->where('id', $id)->delete())
            ->toThrow(QueryException::class, 'Maintenance override refused: set app.lifecycle_reason');
    });

    it('restores a hidden protected row and logs it as a restore', function () {
        // Arrange
        $id = $this->operator->table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);
        overrideOn($this);
        $this->operator->table('lifecycle_test_ledgers')->where('id', $id)->delete();

        // Act
        $this->operator->table('lifecycle_test_ledgers')->where('id', $id)->update(['is_deleted' => false]);

        // Assert
        $row = $this->operator->table('lifecycle_test_ledgers')->where('id', $id)->first();
        $operations = $this->operator->table('maintenance_override_logs')
            ->where('entity_name', 'lifecycle_test_ledgers')
            ->where('record_key', (string) $id)
            ->orderBy('id')
            ->pluck('operation');
        expect($row->is_deleted)->toBeFalse()
            ->and($operations->all())->toBe(['hide', 'restore']);
    });

    it('may jump an enforced status transition', function () {
        // Arrange
        $id = $this->operator->table('lifecycle_test_periods')->insertGetId(['name' => 'November']);
        overrideOn($this);

        // Act
        $this->operator->table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'locked']);

        // Assert
        expect($this->operator->table('lifecycle_test_periods')->where('id', $id)->value('status'))->toBe('locked');
    });

    it('marks the activity trail of its changes', function () {
        // Arrange
        $id = $this->operator->table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);
        overrideOn($this);

        // Act
        $this->operator->table('lifecycle_test_ledgers')->where('id', $id)->delete();

        // Assert
        $hide = $this->operator->table('data_change_logs')
            ->where('entity_name', 'lifecycle_test_ledgers')
            ->where('record_key', (string) $id)
            ->where('operation', 'hide')
            ->sole();
        expect($hide->via_override)->toBeTrue();
    });
});
