<?php

/**
 * audit:change-log-partitions keeps the monthly activity-trail partitions
 * ready before their month starts (design 08 §9).
 */

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function partitionExists(string $name): bool
{
    return DB::scalar('select to_regclass(?)', [$name]) !== null;
}

describe('audit:change-log-partitions', function () {
    it('prepares this month and the next three by default', function () {
        // Arrange
        $this->travelTo('2032-11-20 10:00:00');

        // Act
        $this->artisan('audit:change-log-partitions')->assertSuccessful();

        // Assert
        expect(partitionExists('data_change_logs_2032_11'))->toBeTrue()
            ->and(partitionExists('data_change_logs_2032_12'))->toBeTrue()
            ->and(partitionExists('data_change_logs_2033_01'))->toBeTrue()
            ->and(partitionExists('data_change_logs_2033_02'))->toBeTrue()
            ->and(partitionExists('data_change_logs_2033_03'))->toBeFalse();
    });

    it('prepares only this month with zero months ahead', function () {
        // Arrange
        $this->travelTo('2032-11-20 10:00:00');

        // Act
        $this->artisan('audit:change-log-partitions', ['--months' => '0'])->assertSuccessful();

        // Assert
        expect(partitionExists('data_change_logs_2032_11'))->toBeTrue()
            ->and(partitionExists('data_change_logs_2032_12'))->toBeFalse();
    });

    it('does not skip a short month after a long one', function () {
        // Arrange
        $this->travelTo('2033-01-31 10:00:00');

        // Act
        $this->artisan('audit:change-log-partitions', ['--months' => '1'])->assertSuccessful();

        // Assert
        expect(partitionExists('data_change_logs_2033_02'))->toBeTrue()
            ->and(partitionExists('data_change_logs_2033_03'))->toBeFalse();
    });

    it('reports existing partitions and leaves them alone', function () {
        // Arrange
        $this->travelTo('2032-11-20 10:00:00');
        $this->artisan('audit:change-log-partitions', ['--months' => '0'])->assertSuccessful();

        // Act & Assert
        $this->artisan('audit:change-log-partitions', ['--months' => '0'])
            ->expectsOutputToContain('exists')
            ->assertSuccessful();
    });

    it('refuses an invalid number of months', function () {
        // Act & Assert
        $this->artisan('audit:change-log-partitions', ['--months' => '-1'])
            ->expectsOutputToContain('--months must be a whole number from 0 to 24')
            ->assertFailed();
    });

    it('reports a month the database refuses and prepares the rest', function () {
        // Arrange: PostgreSQL refuses this month (its rows already sit in the
        // default partition, D30); a fresh database never gets there any more,
        // so the connection plays that refusal
        $thisMonth = now()->startOfMonth()->toDateString();
        $nextMonth = now()->startOfMonth()->addMonth()->toDateString();
        $prepared = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('scalar')->andReturnNull();
        $connection->shouldReceive('transaction')->andReturnUsing(fn (Closure $callback) => $callback());
        $connection->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings) use ($thisMonth, &$prepared) {
            if ($bindings[0] === $thisMonth) {
                throw new QueryException('pgsql', $sql, $bindings, new PDOException(
                    'SQLSTATE[23514]: Check violation: 7 ERROR:  updated partition constraint for default partition "data_change_logs_default" would be violated by some row',
                ));
            }
            $prepared[] = $bindings[0];

            return [];
        });
        $this->app->instance(ConnectionInterface::class, $connection);

        // Act & Assert
        $this->artisan('audit:change-log-partitions', ['--months' => '1'])
            ->expectsOutputToContain('would be violated by some row')
            ->assertFailed();
        expect($prepared)->toBe([$nextMonth]);
    });
});

describe('schedule', function () {
    it('runs the partition command every day', function () {
        // Act
        Artisan::call('schedule:list');

        // Assert
        expect(Artisan::output())->toMatch('~10 3 \* \* \*\s+php artisan audit:change-log-partitions~');
    });
});

describe('a new database', function () {
    it('has this month partition before any migration writes a trail row', function () {
        // Arrange: RefreshPostgresDatabase migrated a fresh database
        $name = 'data_change_logs_'.now()->format('Y_m');

        // Act
        $exists = partitionExists($name);

        // Assert
        expect($exists)->toBeTrue();
    });

    it('leaves no trail row in the default partition', function () {
        // Arrange: the migrations wrote rows (for example the bn and en locales)

        // Act
        $stray = DB::table('data_change_logs')->whereRaw("tableoid = 'data_change_logs_default'::regclass")->count();

        // Assert
        expect($stray)->toBe(0);
    });
});
