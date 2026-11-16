<?php

/**
 * LifecycleSchema::freezeColumns() and the generic trigger
 * lifecycle_guard_frozen_columns() (design 08 §2.3b, V7.16), on the fixture
 * table: a lifecycle_test_periods row keeps its name and budget once it
 * leaves "open"; its note stays editable.
 */

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function newTestPeriod(): int
{
    return DB::table('lifecycle_test_periods')->insertGetId(['name' => 'October', 'budget' => 1000]);
}

describe('frozen columns', function () {
    it('can change while the row is in an editable status', function () {
        // Arrange
        $id = newTestPeriod();

        // Act
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['budget' => 1200]);

        // Assert
        expect((float) DB::table('lifecycle_test_periods')->where('id', $id)->value('budget'))->toBe(1200.0);
    });

    it('can still be set by the update that leaves the editable status', function () {
        // Arrange
        $id = newTestPeriod();

        // Act
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'collecting', 'budget' => 1500]);

        // Assert
        expect((float) DB::table('lifecycle_test_periods')->where('id', $id)->value('budget'))->toBe(1500.0);
    });

    it('cannot change once the row has left the editable status', function () {
        // Arrange
        $id = newTestPeriod();
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_periods')->where('id', $id)->update(['budget' => 900]))
            ->toThrow(QueryException::class, "lifecycle_test_periods {$id} is collecting: budget can no longer change");
    });

    it('name every frozen column that was changed', function () {
        // Arrange
        $id = newTestPeriod();
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_periods')->where('id', $id)->update(['name' => 'Nov', 'budget' => 900]))
            ->toThrow(QueryException::class, 'name, budget can no longer change');
    });

    it('leave other columns and the status editable', function () {
        // Arrange
        $id = newTestPeriod();
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Act
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['note' => 'checked', 'status' => 'locked']);

        // Assert
        expect(DB::table('lifecycle_test_periods')->where('id', $id)->first(['note', 'status']))
            ->toEqual((object) ['note' => 'checked', 'status' => 'locked']);
    });

    it('accept an update that writes the same value again', function () {
        // Arrange
        $id = newTestPeriod();
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Act
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['budget' => 1000, 'note' => 'same budget']);

        // Assert
        expect(DB::table('lifecycle_test_periods')->where('id', $id)->value('note'))->toBe('same budget');
    });
});

describe('maintenance override', function () {
    beforeEach(function () {
        $this->operator = DB::connection('pgsql_maintenance');
        $this->operator->beginTransaction();
    });

    afterEach(function () {
        $this->operator->rollBack();
    });

    it('may still correct a frozen column', function () {
        // Arrange
        $id = $this->operator->table('lifecycle_test_periods')->insertGetId(['name' => 'October', 'budget' => 1000]);
        $this->operator->table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'collecting']);
        $this->operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['Audit request #31: budget typed wrong'],
        );

        // Act
        $this->operator->table('lifecycle_test_periods')->where('id', $id)->update(['budget' => 1100]);

        // Assert
        expect((float) $this->operator->table('lifecycle_test_periods')->where('id', $id)->value('budget'))->toBe(1100.0);
    });
});

describe('freezeColumns()', function () {
    // The helper needs the owner role; its changes are rolled back after each test.
    beforeEach(function () {
        $this->owner = DB::connection('pgsql_migrations');
        $this->owner->beginTransaction();
    });

    afterEach(function () {
        $this->owner->rollBack();
    });

    it('creates one named trigger on the table', function () {
        // Act
        $count = $this->owner->table('pg_trigger')->where('tgname', 'trg_lifecycle_test_periods_freeze_fields')->count();

        // Assert
        expect($count)->toBe(1);
    });

    it('refuses what it cannot check', function (array $arguments, string $message) {
        // Act & Assert
        expect(fn () => LifecycleSchema::freezeColumns(...$arguments, connection: 'pgsql_migrations'))
            ->toThrow(QueryException::class, $message);
    })->with([
        'unknown column' => [['lifecycle_test_periods', ['budjet'], ['open']], 'Unknown column of lifecycle_test_periods: budjet'],
        'lifecycle column' => [['lifecycle_test_periods', ['status'], ['open']], 'Lifecycle columns follow the lifecycle rules and cannot be frozen: status'],
        'unknown status' => [['lifecycle_test_periods', ['budget'], ['drafted']], 'Unknown status of lifecycle_test_periods: drafted'],
        'unregistered table' => [['lifecycle_test_entries', ['reference'], ['open']], 'Table lifecycle_test_entries is not registered'],
        'no column' => [['lifecycle_test_periods', [], ['open']], 'Name the columns to freeze of lifecycle_test_periods'],
        'unsafe name' => [['lifecycle_test_periods', ['budget'], ['open'], 'Budget; drop'], 'must be lower_snake_case'],
    ]);

    describe('with no editable status (D47)', function () {
        beforeEach(function () {
            LifecycleSchema::freezeColumns('lifecycle_test_periods', ['note'], editableStatuses: [], name: 'written', connection: 'pgsql_migrations');
            $this->periodId = $this->owner->table('lifecycle_test_periods')->insertGetId(['name' => 'October', 'note' => 'Read on the 1st']);
        });

        it('lets the first write set the columns', function () {
            // Act
            $note = $this->owner->table('lifecycle_test_periods')->where('id', $this->periodId)->value('note');

            // Assert
            expect($note)->toBe('Read on the 1st');
        });

        it('freezes them from the first write, even in an editable-looking status', function () {
            // Act & Assert
            expect(fn () => $this->owner->table('lifecycle_test_periods')->where('id', $this->periodId)->update(['note' => 'Read on the 2nd']))
                ->toThrow(QueryException::class, "lifecycle_test_periods {$this->periodId} is open: note can no longer change");
        });

        it('still lets the row change its status and other columns', function () {
            // Act
            $this->owner->table('lifecycle_test_periods')->where('id', $this->periodId)->update(['status' => 'collecting', 'budget' => 1200]);

            // Assert
            expect($this->owner->table('lifecycle_test_periods')->where('id', $this->periodId)->value('status'))->toBe('collecting');
        });
    });

    it('cannot be called by the runtime role', function () {
        // Act & Assert
        expect(fn () => DB::select("select lifecycle_freeze_columns('lifecycle_test_periods', 'x', array['budget'], array['open'])"))
            ->toThrow(QueryException::class, 'permission denied for function lifecycle_freeze_columns');
    });
});
