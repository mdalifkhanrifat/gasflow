<?php

/**
 * LifecycleSchema::freezeWhenParentClosed() and the generic trigger
 * lifecycle_guard_closed_parent() (design 08, V7.15), on the fixture tables:
 * lifecycle_test_period_lines freeze once their lifecycle_test_periods row is
 * locked.
 */

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A new open fixture period.
 */
function openFixturePeriod(): int
{
    return DB::table('lifecycle_test_periods')->insertGetId(['name' => 'October']);
}

/**
 * Moves an open fixture period along its transitions to the given status.
 */
function moveFixturePeriod(int $id, string $status): void
{
    foreach (['collecting' => ['collecting'], 'locked' => ['collecting', 'locked']][$status] as $next) {
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => $next]);
    }
}

describe('children of an open parent', function () {
    it('can be added', function () {
        // Arrange
        $periodId = openFixturePeriod();

        // Act
        DB::table('lifecycle_test_period_lines')->insert(['period_id' => $periodId, 'amount' => 10]);

        // Assert
        expect(DB::table('lifecycle_test_period_lines')->where('period_id', $periodId)->count())->toBe(1);
    });

    it('can be changed while the parent is not yet closed', function () {
        // Arrange
        $periodId = openFixturePeriod();
        $id = DB::table('lifecycle_test_period_lines')->insertGetId(['period_id' => $periodId, 'amount' => 10]);
        moveFixturePeriod($periodId, 'collecting');

        // Act
        DB::table('lifecycle_test_period_lines')->where('id', $id)->update(['amount' => 12]);

        // Assert
        expect((float) DB::table('lifecycle_test_period_lines')->where('id', $id)->value('amount'))->toBe(12.0);
    });
});

describe('children of a closed parent', function () {
    beforeEach(function () {
        $this->periodId = openFixturePeriod();
        $this->lineId = DB::table('lifecycle_test_period_lines')->insertGetId(['period_id' => $this->periodId, 'amount' => 10]);
        moveFixturePeriod($this->periodId, 'locked');
    });

    it('cannot be added', function () {
        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_period_lines')->insert(['period_id' => $this->periodId, 'amount' => 5]))
            ->toThrow(QueryException::class, "lifecycle_test_periods {$this->periodId} is locked: its lifecycle_test_period_lines can no longer change");
    });

    it('cannot be changed', function () {
        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_period_lines')->where('id', $this->lineId)->update(['amount' => 99]))
            ->toThrow(QueryException::class, 'can no longer change');
    });

    it('cannot be hidden', function () {
        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_period_lines')->where('id', $this->lineId)->delete())
            ->toThrow(QueryException::class, 'can no longer change');
    });

    it('cannot be moved out to an open parent', function () {
        // Arrange
        $openPeriodId = openFixturePeriod();

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_period_lines')->where('id', $this->lineId)->update(['period_id' => $openPeriodId]))
            ->toThrow(QueryException::class, "lifecycle_test_periods {$this->periodId} is locked");
    });

    it('cannot receive a child moved in from an open parent', function () {
        // Arrange
        $openLineId = DB::table('lifecycle_test_period_lines')->insertGetId(['period_id' => openFixturePeriod(), 'amount' => 3]);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_period_lines')->where('id', $openLineId)->update(['period_id' => $this->periodId]))
            ->toThrow(QueryException::class, "lifecycle_test_periods {$this->periodId} is locked");
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

    it('may still correct a child of a closed parent', function () {
        // Arrange
        $periodId = $this->operator->table('lifecycle_test_periods')->insertGetId(['name' => 'October']);
        $lineId = $this->operator->table('lifecycle_test_period_lines')->insertGetId(['period_id' => $periodId, 'amount' => 10]);
        $this->operator->table('lifecycle_test_periods')->where('id', $periodId)->update(['status' => 'collecting']);
        $this->operator->table('lifecycle_test_periods')->where('id', $periodId)->update(['status' => 'locked']);
        $this->operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['Audit request #23: amount typed wrong'],
        );

        // Act
        $this->operator->table('lifecycle_test_period_lines')->where('id', $lineId)->update(['amount' => 12]);

        // Assert
        expect((float) $this->operator->table('lifecycle_test_period_lines')->where('id', $lineId)->value('amount'))->toBe(12.0);
    });
});

describe('freezeWhenParentClosed()', function () {
    // The helper needs the owner role; its changes are rolled back after each test.
    beforeEach(function () {
        $this->owner = DB::connection('pgsql_migrations');
        $this->owner->beginTransaction();
    });

    afterEach(function () {
        $this->owner->rollBack();
    });

    it('creates one named trigger on the child table', function () {
        // Act
        $trigger = $this->owner->table('pg_trigger')
            ->where('tgname', 'trg_lifecycle_test_period_lines_closed_parent')
            ->count();

        // Assert
        expect($trigger)->toBe(1);
    });

    it('refuses what it cannot check', function (array $arguments, string $message) {
        // Act & Assert
        expect(fn () => LifecycleSchema::freezeWhenParentClosed(...$arguments, connection: 'pgsql_migrations'))
            ->toThrow(QueryException::class, $message);
    })->with([
        'unknown status' => [['lifecycle_test_period_lines', 'lifecycle_test_periods', 'period_id', ['closed']], 'Unknown status of lifecycle_test_periods: closed'],
        'unregistered parent' => [['lifecycle_test_period_lines', 'lifecycle_test_entries', 'period_id', ['locked']], 'Parent table lifecycle_test_entries is not registered'],
        'missing column' => [['lifecycle_test_period_lines', 'lifecycle_test_periods', 'month_id', ['locked']], 'Column lifecycle_test_period_lines.month_id does not exist'],
        'no status' => [['lifecycle_test_period_lines', 'lifecycle_test_periods', 'period_id', []], 'Name at least one status'],
    ]);

    it('cannot be called by the runtime role', function () {
        // Act & Assert
        expect(fn () => DB::select("select lifecycle_freeze_with_parent('lifecycle_test_period_lines', 'lifecycle_test_periods', 'period_id', array['open'])"))
            ->toThrow(QueryException::class, 'permission denied for function lifecycle_freeze_with_parent');
    });
});
