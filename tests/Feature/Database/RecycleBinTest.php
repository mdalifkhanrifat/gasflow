<?php

/**
 * Generic recycle bin: lists hidden rows of every table the app may hide
 * (with who / when / why / device / place) and restores them.
 */

use App\Models\User;
use App\Services\Lifecycle\RecycleBin\HiddenRecord;
use App\Services\Lifecycle\RecycleBin\RecycleBin;
use App\Services\Lifecycle\RecycleBin\RestoreHiddenRecord;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\LifecycleTestItem;

function recycleBin(): RecycleBin
{
    return app(RecycleBin::class);
}

function restoreHidden(string $entity, string $recordKey, ?string $reason = null): void
{
    app(RestoreHiddenRecord::class)->handle($entity, $recordKey, $reason);
}

function hiddenItem(string $name = 'gauge', array $context = []): LifecycleTestItem
{
    $item = LifecycleTestItem::create(['name' => $name]);
    setDatabaseContext($context);
    $item->delete();

    return $item;
}

function hiddenCode(string $code): void
{
    DB::table('lifecycle_test_codes')->insert(['code' => $code, 'name' => strtoupper($code)]);
    DB::table('lifecycle_test_codes')->where('code', $code)->delete();
}

describe('tables', function () {
    it('include only tables whose rows the app may hide and restore', function () {
        // Arrange: among the fixture tables only codes and items allow it

        // Act
        $entities = array_keys(recycleBin()->entities());

        // Assert
        expect(array_values(array_filter($entities, fn (string $entity) => str_starts_with($entity, 'lifecycle_test_'))))
            ->toBe(['lifecycle_test_codes', 'lifecycle_test_items']);
    });

    it('include the users table, whose rows are hidden and never removed', function () {
        // Arrange: users is a master table with soft delete allowed

        // Act
        $entities = array_keys(recycleBin()->entities());

        // Assert
        expect($entities)->toContain('users');
    });

    it('report how many hidden rows each table has', function () {
        // Arrange
        hiddenItem('first');
        hiddenItem('second');
        LifecycleTestItem::create(['name' => 'still live']);
        hiddenCode('bkash');

        // Act
        $summary = recycleBin()->summary();

        // Assert
        expect(array_filter($summary, fn (string $entity) => str_starts_with($entity, 'lifecycle_test_'), ARRAY_FILTER_USE_KEY))
            ->toBe(['lifecycle_test_codes' => 1, 'lifecycle_test_items' => 2]);
    });
});

describe('hidden rows', function () {
    it('are listed most recently hidden first, without live rows', function () {
        // Arrange
        LifecycleTestItem::create(['name' => 'still live']);
        foreach (['hidden first' => '2026-10-01 10:00:00+00', 'hidden last' => '2026-11-01 10:00:00+00'] as $name => $deletedAt) {
            $id = LifecycleTestItem::create(['name' => $name])->id;
            DB::table('lifecycle_test_items')->where('id', $id)->update(['deleted_at' => $deletedAt]);
        }

        // Act
        $names = recycleBin()->hidden('lifecycle_test_items')->getCollection()
            ->map(fn (HiddenRecord $hidden) => $hidden->record['name'])
            ->all();

        // Assert
        expect($names)->toBe(['hidden last', 'hidden first']);
    });

    it('keep a stable order when rows were hidden at the same moment', function () {
        // Arrange
        $ids = collect(range(1, 4))->map(fn (int $number) => hiddenItem("item {$number}")->id);

        // Act
        $listed = collect([1, 2])->flatMap(
            fn (int $page) => recycleBin()->hidden('lifecycle_test_items', perPage: 2, page: $page)
                ->getCollection()->map(fn (HiddenRecord $hidden) => (int) $hidden->recordKey),
        );

        // Assert
        expect($listed->all())->toBe($ids->sortDesc()->values()->all());
    });

    it('show who hid the row and why', function () {
        // Arrange
        $user = User::factory()->create(['name' => 'Rahim']);
        $item = hiddenItem(context: ['user_id' => $user->id, 'lifecycle_reason' => 'Duplicate meter']);

        // Act
        $hidden = recycleBin()->hidden('lifecycle_test_items')->first();

        // Assert
        expect($hidden->recordKey)->toBe((string) $item->id)
            ->and($hidden->deletedBy)->toBe($user->id)
            ->and($hidden->deletedByName)->toBe('Rahim')
            ->and($hidden->reason)->toBe('Duplicate meter');
    });

    it('show the device and the place the row was hidden from', function () {
        // Arrange
        hiddenItem(context: ['client_type' => 'pwa', 'device_kind' => 'mobile', 'ip_city' => 'Dhaka', 'ip_country' => 'BD']);

        // Act
        $hidden = recycleBin()->hidden('lifecycle_test_items')->first();

        // Assert
        expect([$hidden->clientType, $hidden->deviceKind, $hidden->ipCity, $hidden->ipCountry])
            ->toBe(['pwa', 'mobile', 'Dhaka', 'BD']);
    });

    it('never show redacted columns', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'gauge', 'secret' => 'p@ssw0rd']);
        $item->delete();

        // Act
        $hidden = recycleBin()->hidden('lifecycle_test_items')->first();

        // Assert
        expect($hidden->record['secret'])->toBe(RecycleBin::REDACTED)
            ->and($hidden->record['name'])->toBe('gauge');
    });

    it('are paginated', function () {
        // Arrange
        foreach (range(1, 3) as $number) {
            hiddenItem("item {$number}");
        }

        // Act
        $page = recycleBin()->hidden('lifecycle_test_items', perPage: 2, page: 2);

        // Assert
        expect($page->total())->toBe(3)
            ->and($page->count())->toBe(1);
    });

    it('cannot be listed for a table outside the recycle bin', function () {
        // Act & Assert
        expect(fn () => recycleBin()->hidden('lifecycle_test_ledgers'))
            ->toThrow(RecordNotFoundException::class, 'is not part of the recycle bin');
    });
});

describe('restoring', function () {
    it('brings a hidden row back and makes it available again', function () {
        // Arrange
        $item = hiddenItem();

        // Act
        restoreHidden('lifecycle_test_items', (string) $item->id);

        // Assert
        $restored = LifecycleTestItem::find($item->id);
        expect($restored)->not->toBeNull()
            ->and($restored->is_deleted)->toBeFalse()
            ->and($restored->is_active)->toBeTrue();
    });

    it('records the reason of the restore', function () {
        // Arrange
        $item = hiddenItem();

        // Act
        restoreHidden('lifecycle_test_items', (string) $item->id, reason: 'Deleted by mistake');

        // Assert
        $log = DB::table('status_change_logs')
            ->where(['entity_name' => 'lifecycle_test_items', 'record_key' => (string) $item->id, 'to_is_deleted' => false])
            ->first();
        expect($log->reason)->toBe('Deleted by mistake');
    });

    it('works for tables with a text primary key', function () {
        // Arrange
        hiddenCode('bkash');

        // Act
        restoreHidden('lifecycle_test_codes', 'bkash');

        // Assert
        expect(DB::table('lifecycle_test_codes')->where('code', 'bkash')->value('is_deleted'))->toBeFalse();
    });

    it('refuses a row that is not hidden', function () {
        // Arrange
        $item = LifecycleTestItem::create(['name' => 'still live']);

        // Act & Assert
        expect(fn () => restoreHidden('lifecycle_test_items', (string) $item->id))
            ->toThrow(RecordNotFoundException::class, 'No hidden row');
    });

    it('refuses a table outside the recycle bin', function () {
        // Arrange
        $id = DB::table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);

        // Act & Assert
        expect(fn () => restoreHidden('lifecycle_test_ledgers', (string) $id))
            ->toThrow(RecordNotFoundException::class, 'is not part of the recycle bin');
    });
});
