<?php

/**
 * Billing schedules as building settings (owner decision D48, design V7.22):
 * one timeline per building and stream, and the cycle bounds it gives.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * The cycle bounds of a building's stream on a day, or null without a setting.
 *
 * @return array{starts_on: ?string, ends_on: ?string, billing_setting_id: int, frequency: string}|null
 */
function billingBounds(int $buildingId, string $day, string $mode = 'authorized_meter_billing'): ?array
{
    $row = DB::selectOne('select * from billing_period_bounds(?, ?, ?)', [$buildingId, $mode, $day]);

    return $row === null ? null : (array) $row;
}

describe('lifecycle registration', function () {
    it('registers the table as billing configuration that may be hidden', function () {
        // Arrange: migration 2026_11_19_000059 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'building_billing_settings')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe(['billing', 'configuration', 'allowed', 'forbidden', false, 'active']);
    });
});

describe('building billing settings', function () {
    it('default to day 1, due after 15 days and the automatic split', function () {
        // Arrange
        $id = TestRecords::billingSetting();

        // Act
        $row = DB::table('building_billing_settings')->where('id', $id)->first();

        // Assert
        expect([$row->cycle_start_day, $row->second_start_day, $row->due_after_days, $row->split_method])
            ->toBe([1, null, 15, 'auto']);
    });

    it('refuse a wrong value', function (array $row, string $constraint) {
        // Arrange: a new building

        // Act & Assert
        expect(fn () => TestRecords::billingSetting(overrides: $row))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'unknown stream' => [['billing_mode' => 'flat_rate'], 'ck_building_billing_settings_billing_mode'],
        'unknown frequency' => [['frequency' => 'weekly'], 'ck_building_billing_settings_frequency'],
        'unknown split' => [['split_method' => 'guess'], 'ck_building_billing_settings_split_method'],
        'start day 0' => [['cycle_start_day' => 0], 'ck_building_billing_settings_cycle_start_day'],
        'start day 29' => [['cycle_start_day' => 29], 'ck_building_billing_settings_cycle_start_day'],
        'negative due days' => [['due_after_days' => -1], 'ck_building_billing_settings_due_after_days'],
        'twice a month without a second day' => [['frequency' => 'semi_monthly'], 'ck_building_billing_settings_second_start_day'],
        'monthly with a second day' => [['second_start_day' => 16], 'ck_building_billing_settings_second_start_day'],
        'second day not after the first' => [['frequency' => 'semi_monthly', 'cycle_start_day' => 16, 'second_start_day' => 16], 'ck_building_billing_settings_second_start_day'],
        'second day 29' => [['frequency' => 'semi_monthly', 'second_start_day' => 29], 'ck_building_billing_settings_second_start_day'],
    ]);

    it('accept twice a month with a later second day', function () {
        // Arrange
        $row = ['frequency' => 'semi_monthly', 'cycle_start_day' => 5, 'second_start_day' => 20];

        // Act
        $id = TestRecords::billingSetting(overrides: $row);

        // Assert
        expect(DB::table('building_billing_settings')->where('id', $id)->value('second_start_day'))->toBe(20);
    });

    it('refuse a second row of the same stream from the same day', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-03-01');

        // Act & Assert
        expect(fn () => TestRecords::billingSetting($buildingId, '2026-03-01'))
            ->toThrow(QueryException::class, 'uq_building_billing_settings_from');
    });

    it('let the other stream start on the same day', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-03-01');

        // Act
        TestRecords::billingSetting($buildingId, '2026-03-01', ['billing_mode' => 'internal_cost_allocation']);

        // Assert
        expect(DB::table('building_billing_settings')->where('building_id', $buildingId)->count())->toBe(2);
    });

    it('free the day of a hidden row', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $hiddenId = TestRecords::billingSetting($buildingId, '2026-03-01');
        DB::table('building_billing_settings')->where('id', $hiddenId)->delete();

        // Act
        TestRecords::billingSetting($buildingId, '2026-03-01');

        // Assert
        expect(DB::table('building_billing_settings')->where('id', $hiddenId)->value('is_deleted'))->toBeTrue();
    });

    it('are frozen from the first write', function (string $column, mixed $value) {
        // Arrange
        $id = TestRecords::billingSetting();

        // Act & Assert
        expect(fn () => DB::table('building_billing_settings')->where('id', $id)->update([$column => $value]))
            ->toThrow(QueryException::class, "{$column} can no longer change");
    })->with([
        ['building_id', fn () => TestRecords::building()],
        ['billing_mode', 'internal_cost_allocation'],
        ['effective_from', '2026-02-01'],
        ['frequency', 'off'],
        ['cycle_start_day', 2],
        ['due_after_days', 30],
        ['split_method', 'days'],
    ]);
});

describe('the governing setting of a day', function () {
    it('is the latest row of the stream from that day or before', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-01-01');
        $marchId = TestRecords::billingSetting($buildingId, '2026-03-01');
        TestRecords::billingSetting($buildingId, '2026-06-01');
        TestRecords::billingSetting($buildingId, '2026-04-01', ['billing_mode' => 'internal_cost_allocation']);

        // Act
        $id = DB::selectOne("select (building_billing_setting_on(?, 'authorized_meter_billing', '2026-05-31')).id", [$buildingId])->id;

        // Assert
        expect($id)->toBe($marchId);
    });

    it('skips a hidden row', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $januaryId = TestRecords::billingSetting($buildingId, '2026-01-01');
        $marchId = TestRecords::billingSetting($buildingId, '2026-03-01');
        DB::table('building_billing_settings')->where('id', $marchId)->delete();

        // Act
        $id = DB::selectOne("select (building_billing_setting_on(?, 'authorized_meter_billing', '2026-05-31')).id", [$buildingId])->id;

        // Assert
        expect($id)->toBe($januaryId);
    });

    it('is none before the first row', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-03-01');

        // Act
        $id = DB::selectOne("select (building_billing_setting_on(?, 'authorized_meter_billing', '2026-02-28')).id", [$buildingId])->id;

        // Assert
        expect($id)->toBeNull();
    });
});

describe('billing period bounds', function () {
    it('follow the pattern of the setting', function (array $setting, string $day, string $startsOn, string $endsOn) {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2025-01-01', $setting);

        // Act
        $bounds = billingBounds($buildingId, $day);

        // Assert
        expect([$bounds['starts_on'], $bounds['ends_on']])->toBe([$startsOn, $endsOn]);
    })->with([
        'calendar month' => [[], '2026-03-15', '2026-03-01', '2026-03-31'],
        'month from the 10th' => [['cycle_start_day' => 10], '2026-03-15', '2026-03-10', '2026-04-09'],
        'month from the 10th, before it' => [['cycle_start_day' => 10], '2026-03-05', '2026-02-10', '2026-03-09'],
        'month from the 10th, over the year end' => [['cycle_start_day' => 10], '2027-01-05', '2026-12-10', '2027-01-09'],
        'month from the 28th, over February' => [['cycle_start_day' => 28], '2026-03-01', '2026-02-28', '2026-03-27'],
        'first half' => [['frequency' => 'semi_monthly', 'second_start_day' => 16], '2026-03-15', '2026-03-01', '2026-03-15'],
        'second half' => [['frequency' => 'semi_monthly', 'second_start_day' => 16], '2026-03-16', '2026-03-16', '2026-03-31'],
        'second half of February' => [['frequency' => 'semi_monthly', 'second_start_day' => 16], '2026-02-20', '2026-02-16', '2026-02-28'],
        'second half from the 20th, before the 5th' => [['frequency' => 'semi_monthly', 'cycle_start_day' => 5, 'second_start_day' => 20], '2026-03-03', '2026-02-20', '2026-03-04'],
        'first half from the 5th' => [['frequency' => 'semi_monthly', 'cycle_start_day' => 5, 'second_start_day' => 20], '2026-03-05', '2026-03-05', '2026-03-19'],
    ]);

    it('name the governing setting and its frequency', function () {
        // Arrange
        $buildingId = TestRecords::building();
        $id = TestRecords::billingSetting($buildingId);

        // Act
        $bounds = billingBounds($buildingId, '2026-03-15');

        // Assert
        expect([$bounds['billing_setting_id'], $bounds['frequency']])->toBe([$id, 'monthly']);
    });

    it('start a short cycle on the day a setting starts', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-03-20');

        // Act
        $bounds = billingBounds($buildingId, '2026-03-25');

        // Assert
        expect([$bounds['starts_on'], $bounds['ends_on']])->toBe(['2026-03-20', '2026-03-31']);
    });

    it('end a short cycle the day before the next setting', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-01-01');
        TestRecords::billingSetting($buildingId, '2026-03-10', ['frequency' => 'semi_monthly', 'second_start_day' => 16]);

        // Act
        $bounds = billingBounds($buildingId, '2026-03-05');

        // Assert
        expect([$bounds['starts_on'], $bounds['ends_on']])->toBe(['2026-03-01', '2026-03-09']);
    });

    it('follow the next setting from its first day', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-01-01');
        TestRecords::billingSetting($buildingId, '2026-03-10', ['frequency' => 'semi_monthly', 'second_start_day' => 16]);

        // Act
        $bounds = billingBounds($buildingId, '2026-03-12');

        // Assert
        expect([$bounds['starts_on'], $bounds['ends_on']])->toBe(['2026-03-10', '2026-03-15']);
    });

    it('are not cut by a hidden next setting', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-01-01');
        $hiddenId = TestRecords::billingSetting($buildingId, '2026-03-10');
        DB::table('building_billing_settings')->where('id', $hiddenId)->delete();

        // Act
        $bounds = billingBounds($buildingId, '2026-03-05');

        // Assert
        expect([$bounds['starts_on'], $bounds['ends_on']])->toBe(['2026-03-01', '2026-03-31']);
    });

    it('give the off range while billing is off', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-05-01', ['frequency' => 'off']);
        TestRecords::billingSetting($buildingId, '2026-08-01');

        // Act
        $bounds = billingBounds($buildingId, '2026-06-15');

        // Assert
        expect([$bounds['frequency'], $bounds['starts_on'], $bounds['ends_on']])->toBe(['off', '2026-05-01', '2026-07-31']);
    });

    it('leave an open off range without an end', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId, '2026-05-01', ['frequency' => 'off']);

        // Act
        $bounds = billingBounds($buildingId, '2026-06-15');

        // Assert
        expect([$bounds['starts_on'], $bounds['ends_on']])->toBe(['2026-05-01', null]);
    });

    it('are none without a setting of the stream', function () {
        // Arrange
        $buildingId = TestRecords::building();
        TestRecords::billingSetting($buildingId);

        // Act
        $bounds = billingBounds($buildingId, '2026-03-15', 'internal_cost_allocation');

        // Assert
        expect($bounds)->toBeNull();
    });
});
