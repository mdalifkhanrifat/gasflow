<?php

/**
 * HasLifecycle: the Eloquent side of the record lifecycle (casts, scopes,
 * transitionTo / activate / deactivate, SoftDeletes interplay).
 */

use App\Shared\Lifecycle\LifecycleViolation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Enums\LifecycleTestPeriodStatus;
use Tests\Fixtures\Models\LifecycleTestItem;
use Tests\Fixtures\Models\LifecycleTestPeriod;

/**
 * @return array{available: LifecycleTestItem, switchedOff: LifecycleTestItem, archived: LifecycleTestItem, deleted: LifecycleTestItem}
 */
function itemsInEveryState(): array
{
    return [
        'available' => LifecycleTestItem::create(['name' => 'available']),
        'switchedOff' => LifecycleTestItem::create(['name' => 'switched off'])->deactivate(),
        'archived' => LifecycleTestItem::create(['name' => 'archived'])->transitionTo('archived'),
        'deleted' => tap(LifecycleTestItem::create(['name' => 'deleted']))->delete(),
    ];
}

/**
 * @return list<string|null>
 */
function statusReasons(string $table, int $id): array
{
    return DB::table('status_change_logs')
        ->where('entity_name', $table)
        ->where('record_key', (string) $id)
        ->orderBy('id')
        ->pluck('reason')
        ->all();
}

describe('new records', function () {
    it('come back with the values the database filled in', function () {
        // Act
        $period = LifecycleTestPeriod::create(['name' => 'October']);

        // Assert
        expect($period->status)->toBe(LifecycleTestPeriodStatus::Open)
            ->and($period->is_active)->toBeTrue()
            ->and($period->is_deleted)->toBeFalse();
    });
});

describe('casts', function () {
    it('casts the lifecycle flags', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge']);

        // Act
        $item->delete();

        // Assert
        expect($item->is_active)->toBeFalse()
            ->and($item->is_deleted)->toBeTrue()
            ->and($item->deleted_at)->toBeInstanceOf(CarbonInterface::class);
    });
});

describe('scopes', function () {
    it('live() leaves out deleted rows', function () {
        // Arrange
        itemsInEveryState();

        // Act
        $names = LifecycleTestItem::withTrashed()->live()->orderBy('id')->pluck('name')->all();

        // Assert
        expect($names)->toBe(['available', 'switched off', 'archived']);
    });

    it('active() returns only available rows', function () {
        // Arrange
        itemsInEveryState();

        // Act
        $names = LifecycleTestItem::active()->pluck('name')->all();

        // Assert
        expect($names)->toBe(['available']);
    });

    it('inactive() returns rows switched off by status or by flag', function () {
        // Arrange
        itemsInEveryState();

        // Act
        $names = LifecycleTestItem::inactive()->orderBy('id')->pluck('name')->all();

        // Assert
        expect($names)->toBe(['switched off', 'archived']);
    });

    it('withStatus() filters by one status code', function () {
        // Arrange
        itemsInEveryState();

        // Act
        $names = LifecycleTestItem::withStatus('archived')->pluck('name')->all();

        // Assert
        expect($names)->toBe(['archived']);
    });

    it('whereStatusIn() filters by several statuses, enums included', function () {
        // Arrange
        $open = LifecycleTestPeriod::create(['name' => 'October']);
        $collecting = LifecycleTestPeriod::create(['name' => 'November'])
            ->transitionTo(LifecycleTestPeriodStatus::Collecting);

        // Act
        $names = LifecycleTestPeriod::whereStatusIn([LifecycleTestPeriodStatus::Open, 'collecting'])
            ->orderBy('id')
            ->pluck('name')
            ->all();

        // Assert
        expect($names)->toBe([$open->name, $collecting->name]);
    });
});

describe('transitionTo()', function () {
    it('changes the status and loads the flags the database derived', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge']);

        // Act
        $item->transitionTo('inactive');

        // Assert
        expect($item->status)->toBe('inactive')
            ->and($item->is_active)->toBeFalse();
    });

    it('records the reason in the status trail', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge']);

        // Act
        $item->transitionTo('archived', reason: 'Replaced by a new gauge');

        // Assert
        expect(statusReasons('lifecycle_test_items', $item->id))->toBe(['Replaced by a new gauge']);
    });

    it('does not carry the reason over to later changes', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge']);
        $item->transitionTo('inactive', reason: 'Seasonal shutdown');

        // Act
        DB::table('lifecycle_test_items')->where('id', $item->id)->update(['status' => 'active']);

        // Assert
        expect(statusReasons('lifecycle_test_items', $item->id))->toBe(['Seasonal shutdown', null]);
    });

    it('accepts a backed enum', function () {
        // Arrange
        $period = LifecycleTestPeriod::create(['name' => 'October']);

        // Act
        $period->transitionTo(LifecycleTestPeriodStatus::Collecting);

        // Assert
        expect($period->status)->toBe(LifecycleTestPeriodStatus::Collecting);
    });

    it('throws a lifecycle violation when the database refuses the transition', function () {
        // Arrange
        $period = LifecycleTestPeriod::create(['name' => 'October']);

        // Act & Assert
        expect(fn () => $period->transitionTo(LifecycleTestPeriodStatus::Locked))
            ->toThrow(LifecycleViolation::class, 'Status transition open -> locked is not allowed');
    });

    it('keeps the model and the transaction usable after a refusal', function () {
        // Arrange
        $period = LifecycleTestPeriod::create(['name' => 'October']);

        // Act
        rescue(fn () => $period->transitionTo(LifecycleTestPeriodStatus::Locked), report: false);

        // Assert
        expect($period->status)->toBe(LifecycleTestPeriodStatus::Open)
            ->and($period->isDirty())->toBeFalse()
            ->and(LifecycleTestPeriod::count())->toBe(1);
    });
});

describe('activate() and deactivate()', function () {
    it('deactivate() switches the record off and keeps its status', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge']);

        // Act
        $item->deactivate(reason: 'Under repair');

        // Assert
        expect($item->is_active)->toBeFalse()
            ->and($item->status)->toBe('active')
            ->and(statusReasons('lifecycle_test_items', $item->id))->toBe(['Under repair']);
    });

    it('activate() makes the record available again', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge'])->deactivate();

        // Act
        $item->activate();

        // Assert
        expect($item->is_active)->toBeTrue();
    });
});

describe('soft delete', function () {
    it('delete() hides the row from default queries', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge']);

        // Act
        $item->delete();

        // Assert
        expect(LifecycleTestItem::find($item->id))->toBeNull()
            ->and(LifecycleTestItem::withTrashed()->find($item->id)->is_deleted)->toBeTrue();
    });

    it('restore() brings the row back and lets the database re-derive is_active', function () {
        // Arrange
        $item = tap(LifecycleTestItem::create(['name' => 'gauge']))->delete();

        // Act
        $item->restore();

        // Assert
        expect($item->is_deleted)->toBeFalse()
            ->and($item->is_active)->toBeTrue()
            ->and(LifecycleTestItem::find($item->id))->not->toBeNull();
    });
});
