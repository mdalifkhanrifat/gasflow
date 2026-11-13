<?php

/**
 * The universal record lifecycle (status / is_active / is_deleted, delete
 * policies, transitions and trails), tested through the restricted runtime role
 * on the test-only tables in tests/Fixtures/migrations.
 *
 * Ported from gasflow-design-v7/tests/lifecycle-tests.sql and activity-tests.sql.
 */

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function insertItem(string $name = 'Item', array $attributes = []): int
{
    return DB::table('lifecycle_test_items')->insertGetId(['name' => $name, ...$attributes]);
}

function findItem(int $id): object
{
    return DB::table('lifecycle_test_items')->where('id', $id)->first();
}

/**
 * @return list<object>
 */
function trailFor(string $table, string $recordKey, string $log = 'data_change_logs'): array
{
    return DB::table($log)
        ->where('entity_name', $table)
        ->where('record_key', $recordKey)
        ->orderBy('id')
        ->get()
        ->all();
}

describe('registry', function () {
    it('provides the generic status set with bangla labels', function () {
        // Act
        $statuses = DB::table('status_definitions')
            ->where('entity_name', '*')
            ->orderBy('sort_order')
            ->get();

        // Assert
        expect($statuses->pluck('code')->all())->toBe(['active', 'inactive', 'archived'])
            ->and($statuses->map(fn ($s) => json_decode($s->translations)->bn->label)->all())
            ->toBe(['সক্রিয়', 'নিষ্ক্রিয়', 'আর্কাইভকৃত']);
    });

    it('does not let the runtime role change the registry', function () {
        // Act & Assert
        expect(fn () => DB::table('lifecycle_entities')
            ->where('entity_name', 'lifecycle_test_ledgers')
            ->update(['soft_delete_policy' => 'allowed']))
            ->toThrow(QueryException::class, 'permission denied for table lifecycle_entities');
    });
});

describe('lifecycle_attach()', function () {
    it('adds the standard lifecycle columns', function () {
        // Act
        $columns = DB::table('information_schema.columns')
            ->where('table_name', 'lifecycle_test_items')
            ->pluck('column_name')
            ->all();

        // Assert
        expect($columns)->toContain(
            'status', 'is_active', 'is_deleted', 'deleted_at', 'created_at', 'updated_at',
            'created_by', 'updated_by', 'created_session_id', 'updated_session_id',
        );
    });

    it('installs the generic lifecycle triggers', function () {
        // Act
        $triggers = DB::table('information_schema.triggers')
            ->where('event_object_table', 'lifecycle_test_items')
            ->distinct()
            ->pluck('trigger_name')
            ->sort()
            ->values()
            ->all();

        // Assert
        expect($triggers)->toBe([
            'trg_00_lifecycle_guard',
            'trg_01_actor_stamp',
            'trg_zz_data_change_log',
            'trg_zz_lifecycle_log',
            'trg_zz_override_audit',
        ]);
    });

    it('adds the consistency checks and the live-row index', function () {
        // Act
        $checks = DB::table('pg_constraint')
            ->where('conrelid', DB::raw("'lifecycle_test_items'::regclass"))
            ->where('contype', 'c')
            ->pluck('conname')
            ->all();
        $index = DB::table('pg_indexes')
            ->where('indexname', 'idx_lifecycle_test_items_live_status')
            ->value('indexdef');

        // Assert
        expect($checks)->toContain('ck_lifecycle_test_items_lifecycle_deleted', 'ck_lifecycle_test_items_lifecycle_active')
            ->and($index)->toContain('WHERE (NOT is_deleted)');
    });
});

describe('new records', function () {
    it('start active, available and not deleted', function () {
        // Act
        $item = findItem(insertItem());

        // Assert
        expect($item->status)->toBe('active')
            ->and($item->is_active)->toBeTrue()
            ->and($item->is_deleted)->toBeFalse()
            ->and($item->deleted_at)->toBeNull();
    });

    it('reject a status that is not in the catalogue', function () {
        // Act & Assert
        expect(fn () => insertItem(attributes: ['status' => 'nonsense']))
            ->toThrow(QueryException::class, 'Status "nonsense" is not defined for lifecycle_test_items');
    });

    it('record the acting user on insert and on update', function () {
        // Arrange
        $creator = User::factory()->create();
        $editor = User::factory()->create();
        setDatabaseContext(['user_id' => $creator->id]);
        $id = insertItem();

        // Act
        setDatabaseContext(['user_id' => $editor->id]);
        DB::table('lifecycle_test_items')->where('id', $id)->update(['name' => 'Renamed']);

        // Assert
        $item = findItem($id);
        expect($item->created_by)->toBe($creator->id)
            ->and($item->updated_by)->toBe($editor->id);
    });
});

describe('status changes', function () {
    it('derive is_active from the status', function () {
        // Arrange
        $id = insertItem();

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->update(['status' => 'inactive']);

        // Assert
        expect(findItem($id)->is_active)->toBeFalse();
    });

    it('are logged with the actor and the reason', function () {
        // Arrange
        $actor = User::factory()->create();
        $id = insertItem();
        setDatabaseContext(['user_id' => $actor->id, 'lifecycle_reason' => 'KYC failed']);

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->update(['status' => 'inactive']);

        // Assert
        $log = trailFor('lifecycle_test_items', (string) $id, 'status_change_logs')[0];
        expect($log->from_status)->toBe('active')
            ->and($log->to_status)->toBe('inactive')
            ->and($log->actor_user_id)->toBe($actor->id)
            ->and($log->reason)->toBe('KYC failed');
    });

    it('leave is_active free to toggle without changing the status', function () {
        // Arrange
        $id = insertItem();

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->update(['is_active' => false]);

        // Assert
        $item = findItem($id);
        expect($item->is_active)->toBeFalse()
            ->and($item->status)->toBe('active')
            ->and($item->is_deleted)->toBeFalse();
    });
});

describe('soft delete', function () {
    it('turns a plain delete into a soft delete', function () {
        // Arrange
        $id = insertItem();

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->delete();

        // Assert
        $item = findItem($id);
        expect($item)->not->toBeNull()
            ->and($item->is_deleted)->toBeTrue()
            ->and($item->deleted_at)->not->toBeNull()
            ->and($item->is_active)->toBeFalse();
    });

    it('records who deleted the row and why', function () {
        // Arrange
        $actor = User::factory()->create();
        $id = insertItem();
        setDatabaseContext(['user_id' => $actor->id, 'lifecycle_reason' => 'Duplicate entry']);

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->delete();

        // Assert
        $log = trailFor('lifecycle_test_items', (string) $id, 'status_change_logs')[0];
        expect($log->to_is_deleted)->toBeTrue()
            ->and($log->actor_user_id)->toBe($actor->id)
            ->and($log->reason)->toBe('Duplicate entry');
    });

    it('treats deleting an already deleted row as a no-op', function () {
        // Arrange
        $id = insertItem();
        DB::table('lifecycle_test_items')->where('id', $id)->delete();
        $deletedAt = findItem($id)->deleted_at;

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->delete();

        // Assert
        expect(findItem($id)->deleted_at)->toBe($deletedAt)
            ->and(trailFor('lifecycle_test_items', (string) $id, 'status_change_logs'))->toHaveCount(1);
    });

    it('restores a row and makes it available again', function () {
        // Arrange
        $id = insertItem();
        DB::table('lifecycle_test_items')->where('id', $id)->delete();

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->update(['is_deleted' => false]);

        // Assert
        $item = findItem($id);
        expect($item->is_deleted)->toBeFalse()
            ->and($item->deleted_at)->toBeNull()
            ->and($item->is_active)->toBeTrue();
    });

    it('marks a row deleted when only deleted_at is set', function () {
        // Arrange
        $id = insertItem();

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->update(['deleted_at' => now()]);

        // Assert
        $item = findItem($id);
        expect($item->is_deleted)->toBeTrue()
            ->and($item->is_active)->toBeFalse();
    });
});

describe('protected records', function () {
    it('cannot be deleted by the app', function () {
        // Arrange
        $id = DB::table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_ledgers')->where('id', $id)->delete())
            ->toThrow(QueryException::class, 'Records in lifecycle_test_ledgers are protected');
    });

    it('cannot be hidden by the app', function () {
        // Arrange
        $id = DB::table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_ledgers')->where('id', $id)->update(['is_deleted' => true]))
            ->toThrow(QueryException::class, 'hiding/restoring needs the backend maintenance override');
    });

    it('cannot be unlocked with the maintenance override by the runtime role', function () {
        // Arrange
        $id = DB::table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);
        setDatabaseContext(['maintenance_override' => 'on', 'lifecycle_reason' => 'Audit request']);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_ledgers')->where('id', $id)->delete())
            ->toThrow(QueryException::class, 'Maintenance override refused: role gasflow_app is not a member of gasflow_maintenance');
    });
});

describe('enforced transitions', function () {
    it('allow a configured transition', function () {
        // Arrange
        $id = DB::table('lifecycle_test_periods')->insertGetId(['name' => 'October']);

        // Act
        DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'collecting']);

        // Assert
        expect(DB::table('lifecycle_test_periods')->where('id', $id)->value('status'))->toBe('collecting');
    });

    it('reject a transition that is not configured', function () {
        // Arrange
        $id = DB::table('lifecycle_test_periods')->insertGetId(['name' => 'October']);

        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_periods')->where('id', $id)->update(['status' => 'locked']))
            ->toThrow(QueryException::class, 'Status transition open -> locked is not allowed');
    });

    it('reject a status that is not initial on insert', function () {
        // Act & Assert
        expect(fn () => DB::table('lifecycle_test_periods')->insert(['name' => 'November', 'status' => 'locked']))
            ->toThrow(QueryException::class, 'Status "locked" is not an initial status');
    });
});

describe('pivot records', function () {
    it('can be hard deleted when the policy allows it', function () {
        // Arrange
        DB::table('lifecycle_test_links')->insert(['left_id' => 1, 'right_id' => 2]);

        // Act
        DB::table('lifecycle_test_links')->where(['left_id' => 1, 'right_id' => 2])->delete();

        // Assert
        expect(DB::table('lifecycle_test_links')->count())->toBe(0);
    });

    it('are logged with a composite record key', function () {
        // Arrange
        DB::table('lifecycle_test_links')->insert(['left_id' => 3, 'right_id' => 7]);

        // Act
        DB::table('lifecycle_test_links')->where(['left_id' => 3, 'right_id' => 7])->update(['status' => 'inactive']);

        // Assert
        expect(trailFor('lifecycle_test_links', '3:7', 'status_change_logs'))->toHaveCount(1);
    });
});

describe('activity trail', function () {
    it('logs an insert with the new values', function () {
        // Act
        $id = insertItem('Pressure gauge');

        // Assert
        $log = trailFor('lifecycle_test_items', (string) $id)[0];
        expect($log->operation)->toBe('insert')
            ->and(json_decode($log->new_values)->name)->toBe('Pressure gauge');
    });

    it('logs only the changed columns with old and new values', function () {
        // Arrange
        $id = insertItem('Old name');

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->update(['name' => 'New name']);

        // Assert
        $log = trailFor('lifecycle_test_items', (string) $id)[1];
        expect($log->operation)->toBe('update')
            ->and($log->changed_columns)->toBe('{name}')
            ->and(json_decode($log->old_values, true))->toBe(['name' => 'Old name'])
            ->and(json_decode($log->new_values, true))->toBe(['name' => 'New name']);
    });

    it('never stores redacted columns', function () {
        // Act
        $id = insertItem(attributes: ['secret' => 'p@ssw0rd']);

        // Assert
        $log = trailFor('lifecycle_test_items', (string) $id)[0];
        expect(json_decode($log->new_values)->secret)->toBe('[redacted]');
    });
});

describe('trail protection', function () {
    it('still records trails for changes made by the runtime role', function () {
        // Arrange
        $id = insertItem();

        // Act
        DB::table('lifecycle_test_items')->where('id', $id)->update(['status' => 'inactive']);

        // Assert
        expect(trailFor('lifecycle_test_items', (string) $id, 'status_change_logs'))->toHaveCount(1)
            ->and(trailFor('lifecycle_test_items', (string) $id))->toHaveCount(2);
    });

    it('does not let the runtime role write a trail table directly', function (string $table) {
        // Arrange
        $fakeRow = ['entity_name' => 'lifecycle_test_items', 'record_key' => '1'];
        if ($table !== 'status_change_logs') {
            $fakeRow['operation'] = 'update';
        }
        if ($table === 'maintenance_override_logs') {
            $fakeRow['reason'] = 'forged';
        }

        // Act & Assert
        expect(fn () => DB::table($table)->insert($fakeRow))
            ->toThrow(QueryException::class, "permission denied for table {$table}");
    })->with([
        'status trail' => 'status_change_logs',
        'activity trail' => 'data_change_logs',
        'activity trail partition' => 'data_change_logs_default',
        'override trail' => 'maintenance_override_logs',
    ]);

    it('does not let the runtime role rewrite existing trail rows', function () {
        // Arrange
        $id = insertItem();
        DB::table('lifecycle_test_items')->where('id', $id)->update(['status' => 'inactive']);

        // Act & Assert
        expect(fn () => DB::table('status_change_logs')->update(['reason' => 'rewritten']))
            ->toThrow(QueryException::class, 'permission denied for table status_change_logs');
    });

    it('does not let the runtime role remove activity trail rows', function () {
        // Arrange
        insertItem();

        // Act & Assert
        expect(fn () => DB::table('data_change_logs')->delete())
            ->toThrow(QueryException::class, 'permission denied for table data_change_logs');
    });
});
