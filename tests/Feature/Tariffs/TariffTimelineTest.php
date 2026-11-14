<?php

/**
 * The tariff timeline (design 01, V7.16, D44): tariff_rate_segments() says
 * which vendor and rate applied on which days; tariff_charge_segments()
 * splits a consumption over those days by the daily average and prices it.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * An active DOMESTIC tariff version of the vendor assignment.
 *
 * @param  array<string, mixed>  $overrides
 */
function activeTariffVersion(int $assignmentId, string $from, ?string $to, float $rate, array $overrides = []): int
{
    $id = TestRecords::tariffVersion($assignmentId, $from, ['effective_to' => $to, 'rate' => $rate, ...$overrides]);
    DB::table('tariff_versions')->where('id', $id)->update(['status' => 'approved']);
    DB::table('tariff_versions')->where('id', $id)->update(['status' => 'active']);

    return $id;
}

/**
 * @return list<array{from: string, to: string, days: int, rate: float|null}>
 */
function rateSegments(int $buildingId, string $from, string $to, ?string $category = null): array
{
    return array_map(fn (object $segment) => [
        'from' => $segment->segment_from,
        'to' => $segment->segment_to,
        'days' => $segment->days,
        'rate' => $segment->rate === null ? null : (float) $segment->rate,
    ], DB::select('select * from tariff_rate_segments(?, ?, ?, ?, ?)', [$buildingId, 'DOMESTIC', $category, $from, $to]));
}

/**
 * @return list<array{days: int, rate: float, quantity: string, amount: string}>
 */
function chargeSegments(int $buildingId, string $from, string $to, float|string $consumption, string $unit = 'm3'): array
{
    return array_map(fn (object $segment) => [
        'days' => $segment->days,
        'rate' => (float) $segment->rate,
        'quantity' => $segment->quantity,
        'amount' => $segment->amount,
    ], DB::select('select * from tariff_charge_segments(?, ?, ?, ?, ?, ?, ?)', [$buildingId, 'DOMESTIC', null, $from, $to, $consumption, $unit]));
}

beforeEach(function () {
    $this->buildingId = TestRecords::building();
    $this->assignmentId = TestRecords::vendorAssignment($this->buildingId, '2026-01-01');
});

describe('rate segments', function () {
    it('give one segment when the rate did not change', function () {
        // Arrange
        activeTariffVersion($this->assignmentId, '2026-01-01', null, 30);

        // Act
        $segments = rateSegments($this->buildingId, '2026-10-01', '2026-10-31');

        // Assert
        expect($segments)->toBe([['from' => '2026-10-01', 'to' => '2026-10-31', 'days' => 31, 'rate' => 30.0]]);
    });

    it('follow every rate change inside one month', function () {
        // Arrange: the price changed on 11 and 21 October
        activeTariffVersion($this->assignmentId, '2026-01-01', '2026-10-10', 30);
        activeTariffVersion($this->assignmentId, '2026-10-11', '2026-10-20', 33);
        activeTariffVersion($this->assignmentId, '2026-10-21', null, 36);

        // Act
        $segments = rateSegments($this->buildingId, '2026-10-01', '2026-10-31');

        // Assert
        expect($segments)->toBe([
            ['from' => '2026-10-01', 'to' => '2026-10-10', 'days' => 10, 'rate' => 30.0],
            ['from' => '2026-10-11', 'to' => '2026-10-20', 'days' => 10, 'rate' => 33.0],
            ['from' => '2026-10-21', 'to' => '2026-10-31', 'days' => 11, 'rate' => 36.0],
        ]);
    });

    it('follow a change of gas vendor inside one month', function () {
        // Arrange: vendor A until 15 October, vendor B (own price) from 16 October
        $buildingId = TestRecords::building();
        $vendorA = TestRecords::vendorAssignment($buildingId, '2026-01-01', ['valid_to' => '2026-10-15', 'status' => 'ended']);
        $vendorB = TestRecords::vendorAssignment($buildingId, '2026-10-16');
        activeTariffVersion($vendorA, '2026-01-01', null, 30);
        activeTariffVersion($vendorB, '2026-10-16', null, 28);

        // Act
        $segments = DB::select('select building_vendor_assignment_id, days, rate from tariff_rate_segments(?, ?, ?, ?, ?)',
            [$buildingId, 'DOMESTIC', null, '2026-10-01', '2026-10-31']);

        // Assert
        expect(array_map(fn ($s) => [$s->building_vendor_assignment_id, $s->days, (float) $s->rate], $segments))
            ->toBe([[$vendorA, 15, 30.0], [$vendorB, 16, 28.0]]);
    });

    it('prefer the customer category rate and fall back to the general one', function () {
        // Arrange: general rate all month; a commercial rate only from 16 October
        activeTariffVersion($this->assignmentId, '2026-01-01', null, 30);
        activeTariffVersion($this->assignmentId, '2026-10-16', null, 45, ['customer_category' => 'commercial']);

        // Act
        $segments = rateSegments($this->buildingId, '2026-10-01', '2026-10-31', 'commercial');

        // Assert
        expect($segments)->toBe([
            ['from' => '2026-10-01', 'to' => '2026-10-15', 'days' => 15, 'rate' => 30.0],
            ['from' => '2026-10-16', 'to' => '2026-10-31', 'days' => 16, 'rate' => 45.0],
        ]);
    });

    it('return to the general rate after a temporary category rate', function () {
        // Arrange: general rate all month; a commercial rate from 11 to 20 October only
        activeTariffVersion($this->assignmentId, '2026-01-01', null, 30);
        activeTariffVersion($this->assignmentId, '2026-10-11', '2026-10-20', 45, ['customer_category' => 'commercial']);

        // Act
        $segments = rateSegments($this->buildingId, '2026-10-01', '2026-10-31', 'commercial');

        // Assert
        expect($segments)->toBe([
            ['from' => '2026-10-01', 'to' => '2026-10-10', 'days' => 10, 'rate' => 30.0],
            ['from' => '2026-10-11', 'to' => '2026-10-20', 'days' => 10, 'rate' => 45.0],
            ['from' => '2026-10-21', 'to' => '2026-10-31', 'days' => 11, 'rate' => 30.0],
        ]);
    });

    it('show days without a rate as a gap', function () {
        // Arrange
        activeTariffVersion($this->assignmentId, '2026-10-06', null, 30);

        // Act
        $segments = rateSegments($this->buildingId, '2026-10-01', '2026-10-31');

        // Assert
        expect($segments)->toBe([
            ['from' => '2026-10-01', 'to' => '2026-10-05', 'days' => 5, 'rate' => null],
            ['from' => '2026-10-06', 'to' => '2026-10-31', 'days' => 26, 'rate' => 30.0],
        ]);
    });

    it('treat days covered only by a draft or a withdrawn version as a gap', function () {
        // Arrange: the active rate ends on 10 October; a draft follows, then a withdrawn (retired) version
        activeTariffVersion($this->assignmentId, '2026-01-01', '2026-10-10', 30);
        TestRecords::tariffVersion($this->assignmentId, '2026-10-11', ['rate' => 99, 'effective_to' => '2026-10-20']);
        $withdrawnId = TestRecords::tariffVersion($this->assignmentId, '2026-10-21', ['rate' => 77]);
        DB::table('tariff_versions')->where('id', $withdrawnId)->update(['status' => 'retired']);

        // Act
        $segments = rateSegments($this->buildingId, '2026-10-01', '2026-10-31');

        // Assert
        expect($segments)->toBe([
            ['from' => '2026-10-01', 'to' => '2026-10-10', 'days' => 10, 'rate' => 30.0],
            ['from' => '2026-10-11', 'to' => '2026-10-31', 'days' => 21, 'rate' => null],
        ]);
    });
});

describe('charge segments', function () {
    it('split the consumption by days and price each part', function () {
        // Arrange: 310 m3 in October, rates 30 / 33 / 36
        activeTariffVersion($this->assignmentId, '2026-01-01', '2026-10-10', 30);
        activeTariffVersion($this->assignmentId, '2026-10-11', '2026-10-20', 33);
        activeTariffVersion($this->assignmentId, '2026-10-21', null, 36);

        // Act
        $charges = chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', 310);

        // Assert
        expect($charges)->toBe([
            ['days' => 10, 'rate' => 30.0, 'quantity' => '100.000000', 'amount' => '3000.00'],
            ['days' => 10, 'rate' => 33.0, 'quantity' => '100.000000', 'amount' => '3300.00'],
            ['days' => 11, 'rate' => 36.0, 'quantity' => '110.000000', 'amount' => '3960.00'],
        ]);
    });

    it('give the rounding remainder to the last segment and round each amount to the paisa', function () {
        // Arrange
        activeTariffVersion($this->assignmentId, '2026-01-01', '2026-10-10', 30);
        activeTariffVersion($this->assignmentId, '2026-10-11', '2026-10-20', 33);
        activeTariffVersion($this->assignmentId, '2026-10-21', null, 36);

        // Act
        $charges = chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', 100);

        // Assert
        expect(array_column($charges, 'quantity'))->toBe(['32.258065', '32.258065', '35.483870'])
            ->and(array_column($charges, 'amount'))->toBe(['967.74', '1064.52', '1277.42'])
            ->and(bcadd(bcadd($charges[0]['quantity'], $charges[1]['quantity'], 6), $charges[2]['quantity'], 6))->toBe('100.000000');
    });

    it('charge nothing for no consumption', function () {
        // Arrange
        activeTariffVersion($this->assignmentId, '2026-01-01', null, 30);

        // Act
        $charges = chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', 0);

        // Assert
        expect(array_column($charges, 'amount'))->toBe(['0.00']);
    });

    it('refuse a period with days that have no rate', function () {
        // Arrange
        activeTariffVersion($this->assignmentId, '2026-10-06', null, 30);

        // Act & Assert
        expect(fn () => chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', 100))
            ->toThrow(QueryException::class, "No approved DOMESTIC tariff for building {$this->buildingId} from 2026-10-01 to 2026-10-05");
    });

    it('refuse a consumption in another unit than the tariff', function () {
        // Arrange
        $id = activeTariffVersion($this->assignmentId, '2026-01-01', null, 30);

        // Act & Assert
        expect(fn () => chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', 100, 'kwh'))
            ->toThrow(QueryException::class, "Tariff version {$id} is priced per m3, the consumption is in kwh");
    });

    it('refuse a negative consumption', function () {
        // Arrange
        activeTariffVersion($this->assignmentId, '2026-01-01', null, 30);

        // Act & Assert
        expect(fn () => chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', -1))
            ->toThrow(QueryException::class, 'Consumption must be zero or more');
    });

    it('refuse a period that ends before it starts', function () {
        // Act & Assert
        expect(fn () => chargeSegments($this->buildingId, '2026-10-31', '2026-10-01', 100))
            ->toThrow(QueryException::class, 'Billing period 2026-10-31 to 2026-10-01 is not a valid range');
    });
});

describe('retroactive rate changes', function () {
    it('show what a past period costs after a rate announced later', function () {
        // Arrange: October was billed at 30; on 20 October a rate of 36 is approved from 16 October
        $oldId = activeTariffVersion($this->assignmentId, '2026-01-01', null, 30);
        $billed = array_sum(array_map('floatval', array_column(chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', 310), 'amount')));
        DB::table('tariff_versions')->where('id', $oldId)->update(['effective_to' => '2026-10-15']);
        activeTariffVersion($this->assignmentId, '2026-10-16', null, 36);

        // Act
        $recalculated = array_sum(array_map('floatval', array_column(chargeSegments($this->buildingId, '2026-10-01', '2026-10-31', 310), 'amount')));

        // Assert: 150 m3 at 30 + 160 m3 at 36 = 10260, so 960 more than the 9300 billed
        expect([$billed, $recalculated, $recalculated - $billed])->toBe([9300.0, 10260.0, 960.0]);
    });
});
