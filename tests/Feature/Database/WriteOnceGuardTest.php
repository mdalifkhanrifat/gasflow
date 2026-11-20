<?php

/**
 * LifecycleSchema::writeOnce() and the generic trigger
 * lifecycle_guard_write_once() (D51, design V7.24), on the fixture table:
 * a lifecycle_test_deliveries row's sent time and reference are written
 * once; its note stays editable.
 */

use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function newTestDelivery(array $row = []): int
{
    return (int) DB::table('lifecycle_test_deliveries')->insertGetId($row + ['note' => 'first']);
}

describe('write-once columns', function () {
    it('can be written while still empty', function () {
        // Arrange
        $id = newTestDelivery();

        // Act
        DB::table('lifecycle_test_deliveries')->where('id', $id)->update(['reference' => 'SMS-1']);

        // Assert
        expect(DB::table('lifecycle_test_deliveries')->where('id', $id)->value('reference'))->toBe('SMS-1');
    });

    it('cannot change once written', function () {
        // Arrange
        $id = newTestDelivery(['reference' => 'SMS-1']);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_deliveries')->where('id', $id)->update(['reference' => 'SMS-2']))
            ->toThrow(QueryException::class, "lifecycle_test_deliveries {$id}: reference is written once");
    });

    it('cannot be emptied once written', function () {
        // Arrange
        $id = newTestDelivery(['sent_at' => now()]);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_deliveries')->where('id', $id)->update(['sent_at' => null]))
            ->toThrow(QueryException::class, 'sent_at is written once');
    });

    it('leaves the other columns editable', function () {
        // Arrange
        $id = newTestDelivery(['reference' => 'SMS-1']);

        // Act
        DB::table('lifecycle_test_deliveries')->where('id', $id)->update(['note' => 'second', 'reference' => 'SMS-1']);

        // Assert
        expect(DB::table('lifecycle_test_deliveries')->where('id', $id)->value('note'))->toBe('second');
    });

    it('lets the maintenance override correct a written column', function () {
        // Arrange
        $operator = DB::connection('pgsql_maintenance');
        $operator->beginTransaction();
        $id = $operator->table('lifecycle_test_deliveries')->insertGetId(['reference' => 'SMS-1']);
        $operator->select("select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)", ['Wrong provider reference']);

        // Act
        $operator->table('lifecycle_test_deliveries')->where('id', $id)->update(['reference' => 'SMS-9']);

        // Assert
        expect($operator->table('lifecycle_test_deliveries')->where('id', $id)->value('reference'))->toBe('SMS-9');
        $operator->rollBack();
    });
});

describe('the rule', function () {
    it('refuses an unknown column, a lifecycle column or no column', function (array $columns, string $error) {
        // Arrange
        $owner = DB::connection('pgsql_migrations');
        $owner->beginTransaction();

        // Act & Assert
        try {
            expect(fn () => LifecycleSchema::writeOnce('lifecycle_test_deliveries', $columns, connection: 'pgsql_migrations'))
                ->toThrow(QueryException::class, $error);
        } finally {
            $owner->rollBack();
        }
    })->with([
        'unknown' => [['nope'], 'Unknown column of lifecycle_test_deliveries: nope'],
        'lifecycle' => [['status'], 'Lifecycle columns follow the lifecycle rules and cannot be written once: status'],
        'none' => [[], 'Name the write-once columns of lifecycle_test_deliveries'],
    ]);

    it('is not open to the runtime role', function () {
        // Arrange: the app connection

        // Act & Assert
        expect(fn () => DB::select("select lifecycle_write_once('lifecycle_test_deliveries', 'x', '{note}')"))
            ->toThrow(QueryException::class, 'permission denied for function lifecycle_write_once');
    });
});
