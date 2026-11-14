<?php

/**
 * Pricing by readings at gas supply changes (design V7.17, D45):
 * meter_reading_points(), meter_usage_between(), metered_charge_segments().
 *
 * The owner's case: on 14 August the building gets vendor A's gas at 50; it
 * runs out after two days and vendor B's gas at 49 follows on 16 August; on
 * 26 August vendor C's gas at 60 follows and lasts about fifty days, past the
 * month end. The main meter (and, when the reader had time, the flat's
 * meter) was read at every change.
 *
 *   day          main meter   flat meter
 *   14 Aug (A)        0           0
 *   16 Aug (B)       40           4
 *   26 Aug (C)      260          30
 *   end of Aug      400          45   (monthly reading on 31 Aug)
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * Builds the owner's case and returns the building, main meter and flat meter.
 *
 * @param  list<string>  $flatReadOn  the change days on which the flat meter was read
 * @param  string  $monthlyReadOn  the day of the monthly readings
 * @param  float|null  $flatReadingAtC  another flat reading at vendor C's arrival (a mistake)
 * @param  bool  $flatUnused  the flat used no gas in August (all its readings 0)
 * @return array{building: int, main: int, flat: int}
 */
function supplyCase(array $flatReadOn = ['2026-08-14', '2026-08-16', '2026-08-26'], bool $verify = true, string $monthlyReadOn = '2026-08-31', ?float $flatReadingAtC = null, bool $flatUnused = false): array
{
    $buildingId = TestRecords::building();
    $mainId = TestRecords::meter($buildingId, ['meter_type' => 'main']);
    $flatMeterId = TestRecords::meter($buildingId);
    $vendors = [
        ['2026-08-14', '2026-08-15', 50, 0, 0],
        ['2026-08-16', '2026-08-25', 49, 40, 4],
        ['2026-08-26', null, 60, 260, 30],
    ];

    foreach ($vendors as [$from, $to, $rate, $mainReading, $flatReading]) {
        $assignmentId = TestRecords::vendorAssignment($buildingId, $from, ['valid_to' => $to, 'status' => $to === null ? 'active' : 'ended']);
        $tariffId = TestRecords::tariffVersion($assignmentId, $from, ['rate' => $rate]);
        DB::table('tariff_versions')->where('id', $tariffId)->update(['status' => 'approved']);
        DB::table('tariff_versions')->where('id', $tariffId)->update(['status' => 'active']);

        $changeId = TestRecords::supplyChange($tariffId, $from);
        TestRecords::supplyChangeReading($changeId, $mainId, $mainReading);
        if (in_array($from, $flatReadOn, true)) {
            TestRecords::supplyChangeReading($changeId, $flatMeterId, $flatUnused ? 0 : ($from === '2026-08-26' ? ($flatReadingAtC ?? $flatReading) : $flatReading));
        }
        if ($verify) {
            DB::table('supply_changes')->where('id', $changeId)->update(['status' => 'verified']);
        }
    }

    $periodId = TestRecords::readingPeriod($buildingId, 8);
    $monthEnd = ['reading_taken_at' => "{$monthlyReadOn} 18:00:00+06", 'previous_reading' => 0];
    TestRecords::meterReading($periodId, $mainId, [...$monthEnd, 'current_reading' => 400, 'consumption' => 400]);
    TestRecords::meterReading($periodId, $flatMeterId, [...$monthEnd, 'current_reading' => $flatUnused ? 0 : 45, 'consumption' => $flatUnused ? 0 : 45]);

    return ['building' => $buildingId, 'main' => $mainId, 'flat' => $flatMeterId];
}

/**
 * The flat's August charges (14 to 31 August, 45 m3) split the given way.
 *
 * @param  array{building: int, main: int, flat: int}  $case
 * @return list<array{rate: float, quantity: string, amount: string, method: string}>
 */
function augustCharges(array $case, string $split, string $from = '2026-08-14', string $to = '2026-08-31', float $consumption = 45): array
{
    return array_map(fn (object $row) => [
        'rate' => (float) $row->rate,
        'quantity' => $row->quantity,
        'amount' => $row->amount,
        'method' => $row->split_method,
    ], DB::select('select * from metered_charge_segments(?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$case['building'], 'DOMESTIC', null, $from, $to, $consumption, 'm3', $case['flat'], $split]));
}

describe('the owner\'s case', function () {
    it('prices each vendor\'s days at that vendor\'s rate', function () {
        // Arrange
        $case = supplyCase();

        // Act
        $segments = DB::select('select segment_from, segment_to, rate from tariff_rate_segments(?, ?, ?, ?, ?)',
            [$case['building'], 'DOMESTIC', null, '2026-08-14', '2026-08-31']);

        // Assert
        expect(array_map(fn ($s) => [$s->segment_from, $s->segment_to, (float) $s->rate], $segments))->toBe([
            ['2026-08-14', '2026-08-15', 50.0],
            ['2026-08-16', '2026-08-25', 49.0],
            ['2026-08-26', '2026-08-31', 60.0],
        ]);
    });

    it('split exactly by the flat meter when it was read at every change', function () {
        // Arrange
        $case = supplyCase();

        // Act
        $charges = augustCharges($case, 'own_meter');

        // Assert: 4 m3 with A, 26 m3 with B, 15 m3 with C
        expect($charges)->toBe([
            ['rate' => 50.0, 'quantity' => '4.000000', 'amount' => '200.00', 'method' => 'own_meter'],
            ['rate' => 49.0, 'quantity' => '26.000000', 'amount' => '1274.00', 'method' => 'own_meter'],
            ['rate' => 60.0, 'quantity' => '15.000000', 'amount' => '900.00', 'method' => 'own_meter'],
        ]);
    });

    it('split by the building\'s main meter usage when only the main meter was read', function () {
        // Arrange: main meter 40 / 220 / 140 of 400 m3
        $case = supplyCase(flatReadOn: []);

        // Act
        $charges = augustCharges($case, 'main_meter');

        // Assert
        expect($charges)->toBe([
            ['rate' => 50.0, 'quantity' => '4.500000', 'amount' => '225.00', 'method' => 'main_meter'],
            ['rate' => 49.0, 'quantity' => '24.750000', 'amount' => '1212.75', 'method' => 'main_meter'],
            ['rate' => 60.0, 'quantity' => '15.750000', 'amount' => '945.00', 'method' => 'main_meter'],
        ]);
    });

    it('still split by the daily average when asked to', function () {
        // Arrange: 2 / 10 / 6 of 18 days
        $case = supplyCase();

        // Act
        $charges = augustCharges($case, 'days');

        // Assert
        expect(array_column($charges, 'quantity'))->toBe(['5.000000', '25.000000', '15.000000'])
            ->and(array_unique(array_column($charges, 'method')))->toBe(['days']);
    });

    it('price September with vendor C alone', function () {
        // Arrange
        $case = supplyCase();

        // Act
        $charges = augustCharges($case, 'auto', '2026-09-01', '2026-09-30', 70);

        // Assert
        expect($charges)->toBe([['rate' => 60.0, 'quantity' => '70.000000', 'amount' => '4200.00', 'method' => 'single_rate']]);
    });
});

describe('automatic split', function () {
    it('use the flat meter when it was read at every change', function () {
        // Arrange
        $case = supplyCase();

        // Act
        $methods = array_unique(array_column(augustCharges($case, 'auto'), 'method'));

        // Assert
        expect($methods)->toBe(['own_meter']);
    });

    it('fall back to the main meter when the flat missed a change', function () {
        // Arrange: the flat was not read when vendor C arrived
        $case = supplyCase(flatReadOn: ['2026-08-14', '2026-08-16']);

        // Act
        $methods = array_unique(array_column(augustCharges($case, 'auto'), 'method'));

        // Assert
        expect($methods)->toBe(['main_meter']);
    });

    it('fall back to the daily average while the changes are not verified', function () {
        // Arrange
        $case = supplyCase(verify: false);

        // Act
        $methods = array_unique(array_column(augustCharges($case, 'auto'), 'method'));

        // Assert
        expect($methods)->toBe(['days']);
    });

    it('fall back from a flat reading that goes down to the main meter', function () {
        // Arrange: the flat's reading at vendor C's arrival was typed as 2 instead of 30
        $case = supplyCase(flatReadingAtC: 2);

        // Act
        $charges = augustCharges($case, 'auto');

        // Assert
        expect(array_unique(array_column($charges, 'method')))->toBe(['main_meter'])
            ->and(array_column($charges, 'quantity'))->toBe(['4.500000', '24.750000', '15.750000']);
    });
});

describe('readings used for the split', function () {
    it('skip a flat meter that shows no usage between the changes', function () {
        // Arrange
        $case = supplyCase(flatUnused: true);

        // Act
        $charges = augustCharges($case, 'auto', consumption: 0);

        // Assert
        expect(array_unique(array_column($charges, 'method')))->toBe(['main_meter'])
            ->and(array_column($charges, 'amount'))->toBe(['0.00', '0.00', '0.00']);
    });

    it('ignore readings of changes that are not verified yet', function () {
        // Arrange: a recorded, unverified extra change on 29 August with a wrong flat reading
        $case = supplyCase();
        $tariffC = DB::table('tariff_versions')->where('building_id', $case['building'])->where('rate', 60)->value('id');
        $pendingId = TestRecords::supplyChange($tariffC, '2026-08-29', ['reason' => 'supply_started']);
        TestRecords::supplyChangeReading($pendingId, $case['flat'], 999);

        // Act
        $charges = augustCharges($case, 'own_meter');

        // Assert
        expect(array_column($charges, 'quantity'))->toBe(['4.000000', '26.000000', '15.000000']);
    });

    it('extend the last interval over days after the last reading', function () {
        // Arrange: the monthly readings were taken on 30 August, so 31 August follows vendor C's daily usage
        $case = supplyCase(monthlyReadOn: '2026-08-30');

        // Act: weights 4 / 26 / 18 of 48
        $charges = augustCharges($case, 'own_meter');

        // Assert
        expect(array_column($charges, 'quantity'))->toBe(['3.750000', '24.375000', '16.875000']);
    });

    it('use only main meters for the main meter split', function () {
        // Arrange: the flat meter was also read at every change
        $case = supplyCase();

        // Act
        $charges = augustCharges($case, 'main_meter');

        // Assert
        expect(array_column($charges, 'quantity'))->toBe(['4.500000', '24.750000', '15.750000']);
    });
});

describe('refused splits', function () {
    it('refuse the flat meter method when the flat missed a change', function () {
        // Arrange
        $case = supplyCase(flatReadOn: ['2026-08-14', '2026-08-16']);

        // Act & Assert
        expect(fn () => augustCharges($case, 'own_meter'))
            ->toThrow(QueryException::class, "Meter {$case['flat']} was not read at every supply change from 2026-08-14 to 2026-08-31");
    });

    it('refuse the main meter method without verified main readings', function () {
        // Arrange
        $case = supplyCase(verify: false);

        // Act & Assert
        expect(fn () => augustCharges($case, 'main_meter'))
            ->toThrow(QueryException::class, 'No main meter of building');
    });

    it('refuse an unknown method', function () {
        // Arrange
        $case = supplyCase();

        // Act & Assert
        expect(fn () => augustCharges($case, 'guess'))
            ->toThrow(QueryException::class, 'Unknown split method guess: use auto, own_meter, main_meter or days');
    });
});

describe('meter usage between days', function () {
    it('spread each interval between two readings over its days', function () {
        // Arrange
        $case = supplyCase();

        // Act: 16 to 20 August is half of the 220 m3 between 16 and 26 August
        $usage = DB::scalar('select meter_usage_between(?, ?, ?)', [$case['main'], '2026-08-16', '2026-08-20']);

        // Assert
        expect((float) $usage)->toBe(110.0);
    });

    it('extend the first interval over days before the first reading', function () {
        // Arrange: installed on 10 August at 0, read 10 on 19 August (a point on 20 August): 1 m3 a day
        $buildingId = TestRecords::building();
        $meterId = TestRecords::meter($buildingId, ['installed_at' => '2026-08-10', 'initial_reading' => 0]);
        $periodId = TestRecords::readingPeriod($buildingId, 8);
        TestRecords::meterReading($periodId, $meterId, ['reading_taken_at' => '2026-08-19 18:00:00+06', 'previous_reading' => 0, 'current_reading' => 10, 'consumption' => 10]);

        // Act
        $usage = DB::scalar('select meter_usage_between(?, ?, ?)', [$meterId, '2026-08-08', '2026-08-09']);

        // Assert
        expect((float) $usage)->toBe(2.0);
    });

    it('know nothing about a meter with fewer than two readings', function () {
        // Arrange
        $meterId = TestRecords::meter();

        // Act
        $usage = DB::scalar('select meter_usage_between(?, ?, ?)', [$meterId, '2026-08-01', '2026-08-31']);

        // Assert
        expect($usage)->toBeNull();
    });

    it('know nothing across a reading that goes down', function () {
        // Arrange: a meter replaced or reset shows a lower reading
        $buildingId = TestRecords::building();
        $meterId = TestRecords::meter($buildingId, ['installed_at' => '2026-08-01', 'initial_reading' => 500]);
        $periodId = TestRecords::readingPeriod($buildingId, 8);
        TestRecords::meterReading($periodId, $meterId, ['reading_taken_at' => '2026-08-31 18:00:00+06', 'previous_reading' => 0, 'current_reading' => 20, 'consumption' => 20]);

        // Act
        $usage = DB::scalar('select meter_usage_between(?, ?, ?)', [$meterId, '2026-08-01', '2026-08-31']);

        // Assert
        expect($usage)->toBeNull();
    });
});
