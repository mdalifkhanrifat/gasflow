<?php

/**
 * Every web request tells the database which device it came from and where:
 * client type, device kind, IP city/country and the consented GPS fix.
 */

use App\Shared\RequestContext\GeoFix;
use App\Shared\RequestContext\IpLocation;
use App\Shared\RequestContext\IpLocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Models\LifecycleTestItem;

const LAPTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const ANDROID_PHONE = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36';
const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

/**
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function requestContextFor(array $headers = [], string $ip = '127.0.0.1'): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders($headers)
        ->getJson('/_test/request-context')
        ->json();
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-13 10:00:00');

    // Dhaka for one public address, nothing for the rest.
    app()->instance(IpLocator::class, new class implements IpLocator
    {
        public function locate(string $ip): ?IpLocation
        {
            return $ip === '103.4.145.2' ? new IpLocation('BD', 'Dhaka') : null;
        }
    });

    Route::middleware('web')->group(function () {
        Route::get('/_test/request-context', fn () => collect([
            'client_type', 'device_kind', 'ip', 'ip_country', 'ip_city', 'geo_lat', 'geo_lon', 'geo_accuracy_m', 'geo_source',
        ])->mapWithKeys(fn (string $key) => [$key => (string) DB::scalar('select current_setting(?, true)', ["app.{$key}"])]));
        Route::post('/_test/request-context/items', fn () => ['id' => LifecycleTestItem::create(['name' => 'from phone'])->id]);
    });
});

describe('device', function () {
    it('recognises a laptop browser as desktop', function () {
        // Act
        $context = requestContextFor(['User-Agent' => LAPTOP_CHROME]);

        // Assert
        expect($context['device_kind'])->toBe('desktop')
            ->and($context['client_type'])->toBe('browser');
    });

    it('recognises a phone as mobile', function () {
        // Act
        $context = requestContextFor(['User-Agent' => ANDROID_PHONE]);

        // Assert
        expect($context['device_kind'])->toBe('mobile');
    });

    it('recognises a tablet', function () {
        // Act
        $context = requestContextFor(['User-Agent' => IPAD]);

        // Assert
        expect($context['device_kind'])->toBe('tablet');
    });

    it('marks an unrecognised client as unknown', function () {
        // Act
        $context = requestContextFor(['User-Agent' => 'curl/8.5.0']);

        // Assert
        expect($context['device_kind'])->toBe('unknown');
    });

    it('takes the client type from the X-Client header', function () {
        // Act
        $context = requestContextFor(['User-Agent' => ANDROID_PHONE, 'X-Client' => 'pwa']);

        // Assert
        expect($context['client_type'])->toBe('pwa');
    });

    it('ignores a client type it does not know', function () {
        // Act
        $context = requestContextFor(['X-Client' => 'maintenance']);

        // Assert
        expect($context['client_type'])->toBe('browser');
    });
});

describe('location', function () {
    it('looks up the city and country of the IP address', function () {
        // Act
        $context = requestContextFor(ip: '103.4.145.2');

        // Assert
        expect([$context['ip'], $context['ip_city'], $context['ip_country'], $context['geo_source']])
            ->toBe(['103.4.145.2', 'Dhaka', 'BD', 'ip']);
    });

    it('stores a consented gps fix from the X-Geo header', function () {
        // Arrange
        $takenAt = now()->subMinutes(2)->timestamp;

        // Act
        $context = requestContextFor(['X-Geo' => "23.8103,90.4125,12.5,{$takenAt}"], ip: '103.4.145.2');

        // Assert
        expect([$context['geo_lat'], $context['geo_lon'], $context['geo_accuracy_m'], $context['geo_source']])
            ->toBe(['23.8103', '90.4125', '12.5', 'gps']);
    });

    it('ignores a gps fix that is too old', function () {
        // Arrange
        $takenAt = now()->subMinutes(GeoFix::MAX_AGE_MINUTES + 1)->timestamp;

        // Act
        $context = requestContextFor(['X-Geo' => "23.8103,90.4125,12.5,{$takenAt}"], ip: '103.4.145.2');

        // Assert
        expect($context['geo_lat'])->toBe('')
            ->and($context['geo_source'])->toBe('ip');
    });

    it('ignores a malformed or impossible gps fix', function (string $header) {
        // Act
        $context = requestContextFor(['X-Geo' => $header]);

        // Assert
        expect($context['geo_lat'])->toBe('')
            ->and($context['geo_source'])->toBe('none');
    })->with([
        'latitude out of range' => '123.0,90.4,10',
        'not numbers' => 'north,east,10',
        'missing accuracy' => '23.81,90.41',
        'accuracy too large' => '23.81,90.41,500000',
    ]);

    it('reports no location when nothing is known', function () {
        // Act
        $context = requestContextFor(ip: '10.0.0.5');

        // Assert
        expect($context['ip_city'])->toBe('')
            ->and($context['geo_source'])->toBe('none');
    });
});

describe('activity trail', function () {
    it('records the device and place with every change', function () {
        // Arrange
        $headers = ['User-Agent' => ANDROID_PHONE, 'X-Client' => 'pwa', 'X-Geo' => '23.8103,90.4125,12.5'];

        // Act
        $id = $this->withServerVariables(['REMOTE_ADDR' => '103.4.145.2'])
            ->withHeaders($headers)
            ->postJson('/_test/request-context/items')
            ->json('id');

        // Assert
        $log = DB::table('data_change_logs')
            ->where(['entity_name' => 'lifecycle_test_items', 'record_key' => (string) $id])
            ->first();
        expect([$log->client_type, $log->device_kind, $log->ip_address, $log->ip_city, $log->ip_country, $log->geo_source])
            ->toBe(['pwa', 'mobile', '103.4.145.2', 'Dhaka', 'BD', 'gps'])
            ->and((float) $log->geo_lat)->toBe(23.8103);
    });
});
