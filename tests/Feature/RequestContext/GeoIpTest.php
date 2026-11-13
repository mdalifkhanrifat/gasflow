<?php

/**
 * IP → city lookups from the local DB-IP database, and its monthly download.
 */

use App\Shared\RequestContext\DbIpLocator;
use GeoIp2\Database\Reader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeMaxMindDatabase;

beforeEach(function () {
    $this->geoipDirectory = storage_path('framework/testing/geoip-'.uniqid());
    $this->databasePath = "{$this->geoipDirectory}/dbip-city-lite.mmdb";
    config([
        'audit.geoip.database' => $this->databasePath,
        'audit.geoip.download_url' => 'https://download.example.test/dbip-city-lite-%s.mmdb.gz',
    ]);
    Carbon::setTestNow('2026-11-13 10:00:00');
});

afterEach(function () {
    foreach (glob("{$this->geoipDirectory}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($this->geoipDirectory)) {
        rmdir($this->geoipDirectory);
    }
});

describe('DbIpLocator', function () {
    it('knows nothing about private addresses', function () {
        // Arrange
        $locator = new DbIpLocator(config('audit.geoip.database'));

        // Act
        $location = $locator->locate('192.168.1.10');

        // Assert
        expect($location)->toBeNull();
    });

    it('knows nothing when the database has not been downloaded', function () {
        // Arrange
        $locator = new DbIpLocator('/nonexistent/dbip-city-lite.mmdb');

        // Act
        $location = $locator->locate('8.8.8.8');

        // Assert
        expect($location)->toBeNull();
    });

    it('finds the city and country of a public address', function () {
        // Arrange
        $locator = new DbIpLocator(storage_path('app/geoip/dbip-city-lite.mmdb'));

        // Act
        $location = $locator->locate('8.8.8.8');

        // Assert
        expect($location->countryCode)->toBe('US')
            ->and($location->city)->not->toBeEmpty();
    })->skip(
        fn () => ! is_file(storage_path('app/geoip/dbip-city-lite.mmdb')),
        'the real DB-IP database is not downloaded (make geoip)',
    );
});

describe('audit:geoip-update', function () {
    it("installs this month's database", function () {
        // Arrange
        Http::fake(['*dbip-city-lite-2026-11.mmdb.gz' => Http::response(gzencode(FakeMaxMindDatabase::bytes()))]);

        // Act
        $this->artisan('audit:geoip-update')->assertSuccessful();

        // Assert
        expect((new Reader($this->databasePath))->metadata()->databaseType)->toBe('DBIP-City-Lite');
    });

    it("falls back to last month's database when this month's is not published yet", function () {
        // Arrange
        Http::fake([
            '*dbip-city-lite-2026-11.mmdb.gz' => Http::response('Not found', 404),
            '*dbip-city-lite-2026-10.mmdb.gz' => Http::response(gzencode(FakeMaxMindDatabase::bytes())),
        ]);

        // Act
        $this->artisan('audit:geoip-update')->assertSuccessful();

        // Assert
        expect(is_file($this->databasePath))->toBeTrue();
    });

    it('keeps the current database when the download is not a database', function () {
        // Arrange
        mkdir($this->geoipDirectory, 0775, true);
        file_put_contents($this->databasePath, FakeMaxMindDatabase::bytes('Current-City-Database'));
        Http::fake(['*' => Http::response(gzencode('<html>maintenance page</html>'))]);

        // Act
        $this->artisan('audit:geoip-update')->assertFailed();

        // Assert
        expect((new Reader($this->databasePath))->metadata()->databaseType)->toBe('Current-City-Database')
            ->and(glob("{$this->geoipDirectory}/*"))->toBe([$this->databasePath]);
    });

    it('refuses a database that is not a city database', function () {
        // Arrange
        Http::fake(['*' => Http::response(gzencode(FakeMaxMindDatabase::bytes('DBIP-Country-Lite')))]);

        // Act
        $this->artisan('audit:geoip-update')->assertFailed();

        // Assert
        expect(is_file($this->databasePath))->toBeFalse();
    });
});
