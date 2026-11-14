<?php

/**
 * Read-only lock (design 09 §3): without a valid license every read still
 * works and every write is refused with 423, through the real web stack.
 */

use App\Modules\Licensing\Exceptions\LicenseLocked;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestLicense;

beforeEach(function () {
    $this->writes = 0;

    Route::middleware('web')->group(function () {
        Route::get('/_licensing-test/records', fn () => 'records');
        Route::post('/_licensing-test/records', function () {
            $this->writes++;

            return 'written';
        })->name('licensing-test.write');
        Route::post('/_licensing-test/login', fn () => 'signed in')->name('licensing-test.login');
    });

    app('router')->getRoutes()->refreshNameLookups();
});

describe('licensed installation', function () {
    it('lets writes through while the license is active', function () {
        // Arrange: TestCase installs an active license

        // Act
        $response = $this->post('/_licensing-test/records');

        // Assert
        $response->assertOk()->assertSee('written');
        expect($this->writes)->toBe(1);
    });

    it('lets writes through during the grace period', function () {
        // Arrange
        TestLicense::install($this->app, TestLicense::inGrace());

        // Act
        $response = $this->post('/_licensing-test/records');

        // Assert
        $response->assertOk();
        expect($this->writes)->toBe(1);
    });
});

describe('locked installation', function () {
    it('refuses a write when the license has expired', function () {
        // Arrange
        TestLicense::install($this->app, TestLicense::expired());

        // Act
        $response = $this->post('/_licensing-test/records');

        // Assert
        $response->assertStatus(423);
        expect($this->writes)->toBe(0);
    });

    it('refuses a write when no license is installed', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $response = $this->post('/_licensing-test/records');

        // Assert
        $response->assertStatus(423);
        expect($this->writes)->toBe(0);
    });

    it('refuses a write when the license is signed by an untrusted key', function () {
        // Arrange
        TestLicense::install($this->app, trusted: false);

        // Act
        $response = $this->post('/_licensing-test/records');

        // Assert
        $response->assertStatus(423);
        expect($this->writes)->toBe(0);
    });

    it('refuses updates and deletes as well', function (string $method) {
        // Arrange
        TestLicense::remove($this->app);
        Route::middleware('web')->match([$method], '/_licensing-test/records/1', fn () => 'changed');

        // Act
        $response = $this->call($method, '/_licensing-test/records/1');

        // Assert
        $response->assertStatus(423);
    })->with(['PUT', 'PATCH', 'DELETE']);

    it('still serves reads, so data can be seen and exported', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $response = $this->get('/_licensing-test/records');

        // Assert
        $response->assertOk()->assertSee('records');
    });

    it('lets the configured routes write, such as signing in', function () {
        // Arrange
        TestLicense::remove($this->app);
        config(['licensing.locked_write_routes' => ['licensing-test.login']]);

        // Act
        $response = $this->post('/_licensing-test/login');

        // Assert
        $response->assertOk()->assertSee('signed in');
    });

    it('does not let other routes write when some are configured', function () {
        // Arrange
        TestLicense::remove($this->app);
        config(['licensing.locked_write_routes' => ['licensing-test.login']]);

        // Act
        $response = $this->post('/_licensing-test/records');

        // Assert
        $response->assertStatus(423);
    });

    it('tells json clients the installation is read-only without saying why', function () {
        // Arrange
        TestLicense::install($this->app, TestLicense::expired());

        // Act
        $response = $this->postJson('/_licensing-test/records');

        // Assert
        $response->assertStatus(423)->assertJsonPath('message', LicenseLocked::MESSAGE);
    });
});
