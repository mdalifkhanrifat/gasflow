<?php

/**
 * Billing cycles follow the building's billing settings, and a stream's
 * timeline never changes under its cycles (owner decision D48, design V7.22).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * A draft cycle of the setting's building and stream over the given days,
 * labelled with the month it ends in, on the building's approved reading
 * period of its first month.
 *
 * @param  array<string, mixed>  $overrides
 */
function scheduledCycle(int $settingId, string $startsOn, string $endsOn, array $overrides = []): int
{
    $setting = DB::table('building_billing_settings')->where('id', $settingId)->first();
    $month = (int) substr($startsOn, 5, 2);
    $periodId = DB::table('reading_periods')->where('building_id', $setting->building_id)->where('period_month', $month)->value('id')
        ?? TestRecords::approvedReadingPeriod($setting->building_id, $month);

    return (int) DB::table('billing_cycles')->insertGetId([
        'building_id' => $setting->building_id,
        'reading_period_id' => $periodId,
        'billing_setting_id' => $settingId,
        'billing_mode' => $setting->billing_mode,
        'period_year' => (int) substr($endsOn, 0, 4),
        'period_month' => (int) substr($endsOn, 5, 2),
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'due_on' => $endsOn,
        ...$overrides,
    ]);
}

describe('billing cycles under a billing setting', function () {
    it('take both halves of a month when billing twice a month', function () {
        // Arrange
        $settingId = TestRecords::billingSetting(overrides: ['frequency' => 'semi_monthly', 'second_start_day' => 16]);

        // Act
        scheduledCycle($settingId, '2026-10-01', '2026-10-15');
        scheduledCycle($settingId, '2026-10-16', '2026-10-31');

        // Assert
        expect(DB::table('billing_cycles')->where('billing_setting_id', $settingId)->count())->toBe(2);
    });

    it('let the other stream bill the same days', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $authorizedId = TestRecords::billingSetting($buildingId);
        $internalId = TestRecords::billingSetting($buildingId, overrides: ['billing_mode' => 'internal_cost_allocation']);
        scheduledCycle($authorizedId, '2026-10-01', '2026-10-31');

        // Act
        scheduledCycle($internalId, '2026-10-01', '2026-10-31');

        // Assert
        expect(DB::table('billing_cycles')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('bill the days of a cancelled cycle again', function () {
        // Arrange
        $settingId = TestRecords::billingSetting();
        $cancelledId = scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        DB::table('billing_cycles')->where('id', $cancelledId)->update(['status' => 'cancelled']);

        // Act
        scheduledCycle($settingId, '2026-10-01', '2026-10-31');

        // Assert
        expect(DB::table('billing_cycles')->where('billing_setting_id', $settingId)->count())->toBe(2);
    });

    it('refuse days other than the setting\'s cycle', function () {
        // Arrange
        $settingId = TestRecords::billingSetting(overrides: ['frequency' => 'semi_monthly', 'second_start_day' => 16]);

        // Act & Assert
        expect(fn () => scheduledCycle($settingId, '2026-10-01', '2026-10-31'))
            ->toThrow(QueryException::class, 'runs 2026-10-01 to 2026-10-15, not 2026-10-01 to 2026-10-31');
    });

    it('cut the cycle before a change of setting', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $monthlyId = TestRecords::billingSetting($buildingId);
        TestRecords::billingSetting($buildingId, '2026-10-10', ['frequency' => 'semi_monthly', 'second_start_day' => 16]);

        // Act
        $id = scheduledCycle($monthlyId, '2026-10-01', '2026-10-09');

        // Assert
        expect(DB::table('billing_cycles')->where('id', $id)->value('ends_on'))->toBe('2026-10-09');
    });

    it('refuse a cycle before the stream has a setting', function () {
        // Arrange
        $settingId = TestRecords::billingSetting(effectiveFrom: '2026-11-01');

        // Act & Assert
        expect(fn () => scheduledCycle($settingId, '2026-10-01', '2026-10-31'))
            ->toThrow(QueryException::class, 'has no authorized_meter_billing billing setting on 2026-10-01');
    });

    it('refuse a cycle while billing is off', function () {
        // Arrange
        $settingId = TestRecords::billingSetting(overrides: ['frequency' => 'off']);

        // Act & Assert
        expect(fn () => scheduledCycle($settingId, '2026-10-01', '2026-10-31'))
            ->toThrow(QueryException::class, 'is off on 2026-10-01');
    });

    it('refuse a setting that does not govern the first day', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $januaryId = TestRecords::billingSetting($buildingId);
        $octoberId = TestRecords::billingSetting($buildingId, '2026-10-01');

        // Act & Assert
        expect(fn () => scheduledCycle($januaryId, '2026-10-01', '2026-10-31'))
            ->toThrow(QueryException::class, "follows billing setting {$octoberId}, not {$januaryId}");
    });

    it('label the month they end in', function () {
        // Arrange
        $settingId = TestRecords::billingSetting(overrides: ['cycle_start_day' => 10]);

        // Act
        $id = scheduledCycle($settingId, '2026-10-10', '2026-11-09');

        // Assert
        expect(DB::table('billing_cycles')->where('id', $id)->value('period_month'))->toBe(11);
    });

    it('refuse a label of another month', function () {
        // Arrange
        $settingId = TestRecords::billingSetting(overrides: ['cycle_start_day' => 10]);

        // Act & Assert
        expect(fn () => scheduledCycle($settingId, '2026-10-10', '2026-11-09', ['period_month' => 10]))
            ->toThrow(QueryException::class, 'ck_billing_cycles_period_of_ends_on');
    });

    it('freeze their schedule after draft', function (string $column, Closure $value) {
        // Arrange
        $settingId = TestRecords::billingSetting();
        $id = scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        DB::table('billing_cycles')->where('id', $id)->update(['status' => 'calculating']);

        // Act & Assert
        expect(fn () => DB::table('billing_cycles')->where('id', $id)->update([$column => $value($settingId)]))
            ->toThrow(QueryException::class, "{$column} can no longer change");
    })->with([
        'reading period' => ['reading_period_id', fn (int $settingId) => TestRecords::approvedReadingPeriod(
            DB::table('building_billing_settings')->where('id', $settingId)->value('building_id'), 11)],
        'setting' => ['billing_setting_id', fn (int $settingId) => TestRecords::billingSetting(
            DB::table('building_billing_settings')->where('id', $settingId)->value('building_id'), '2026-12-01')],
        'start' => ['starts_on', fn () => '2026-10-02'],
        'end' => ['ends_on', fn () => '2026-10-30'],
        'label' => ['period_month', fn () => 11],
    ]);

    it('may still move to another schedule while a draft', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $settingId = TestRecords::billingSetting($buildingId);
        $id = scheduledCycle($settingId, '2026-10-01', '2026-10-31');

        // Act
        DB::table('billing_cycles')->where('id', $id)->update(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'period_month' => 9]);

        // Assert
        expect(DB::table('billing_cycles')->where('id', $id)->value('starts_on'))->toBe('2026-09-01');
    });

    it('refuse a draft moved off the setting\'s cycle', function () {
        // Arrange
        $settingId = TestRecords::billingSetting();
        $id = scheduledCycle($settingId, '2026-10-01', '2026-10-31');

        // Act & Assert
        expect(fn () => DB::table('billing_cycles')->where('id', $id)->update(['ends_on' => '2026-10-20']))
            ->toThrow(QueryException::class, 'runs 2026-10-01 to 2026-10-31, not 2026-10-01 to 2026-10-20');
    });
});

describe('the billing timeline of a stream', function () {
    it('takes a new setting from the day after its last cycle', function () {
        // Arrange
        $settingId = TestRecords::billingSetting();
        scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        $buildingId = DB::table('building_billing_settings')->where('id', $settingId)->value('building_id');

        // Act
        $id = TestRecords::billingSetting($buildingId, '2026-11-01');

        // Assert
        expect($id)->toBeGreaterThan($settingId);
    });

    it('refuses a new setting inside the billed days', function () {
        // Arrange
        $settingId = TestRecords::billingSetting();
        scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        $buildingId = DB::table('building_billing_settings')->where('id', $settingId)->value('building_id');

        // Act & Assert
        expect(fn () => TestRecords::billingSetting($buildingId, '2026-10-31'))
            ->toThrow(QueryException::class, 'is billed until 2026-10-31');
    });

    it('ignores cancelled cycles', function () {
        // Arrange
        $settingId = TestRecords::billingSetting();
        $cycleId = scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        DB::table('billing_cycles')->where('id', $cycleId)->update(['status' => 'cancelled']);
        $buildingId = DB::table('building_billing_settings')->where('id', $settingId)->value('building_id');

        // Act
        $id = TestRecords::billingSetting($buildingId, '2026-10-15');

        // Assert
        expect($id)->toBeGreaterThan($settingId);
    });

    it('is the stream\'s own', function () {
        // Arrange
        $settingId = TestRecords::billingSetting();
        scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        $buildingId = DB::table('building_billing_settings')->where('id', $settingId)->value('building_id');

        // Act
        $id = TestRecords::billingSetting($buildingId, '2026-10-15', ['billing_mode' => 'internal_cost_allocation']);

        // Assert
        expect($id)->toBeGreaterThan($settingId);
    });

    it('keeps a setting that may have shaped its cycles', function (string $effectiveFrom) {
        // Arrange
        $settingId = TestRecords::billingSetting();
        scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        $buildingId = DB::table('building_billing_settings')->where('id', $settingId)->value('building_id');
        $laterId = $effectiveFrom === '2026-01-01' ? $settingId : TestRecords::billingSetting($buildingId, $effectiveFrom);

        // Act & Assert
        expect(fn () => DB::table('building_billing_settings')->where('id', $laterId)->delete())
            ->toThrow(QueryException::class, 'shaped cycles until 2026-10-31');
    })->with([
        'the governing one' => '2026-01-01',
        'one from the day after' => '2026-11-01',
    ]);

    it('lets a later unused setting be hidden', function () {
        // Arrange
        $settingId = TestRecords::billingSetting();
        scheduledCycle($settingId, '2026-10-01', '2026-10-31');
        $buildingId = DB::table('building_billing_settings')->where('id', $settingId)->value('building_id');
        $laterId = TestRecords::billingSetting($buildingId, '2026-11-02');

        // Act
        DB::table('building_billing_settings')->where('id', $laterId)->delete();

        // Assert
        expect(DB::table('building_billing_settings')->where('id', $laterId)->value('is_deleted'))->toBeTrue();
    });

    it('refuses to restore a setting inside the billed days', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $settingId = TestRecords::billingSetting($buildingId);
        $hiddenId = TestRecords::billingSetting($buildingId, '2026-10-15');
        DB::table('building_billing_settings')->where('id', $hiddenId)->delete();
        scheduledCycle($settingId, '2026-10-01', '2026-10-31');

        // Act & Assert
        expect(fn () => DB::table('building_billing_settings')->where('id', $hiddenId)->update(['is_deleted' => false, 'deleted_at' => null]))
            ->toThrow(QueryException::class, 'is billed until 2026-10-31');
    });
});
