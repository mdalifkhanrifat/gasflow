<?php

/**
 * The application's verifier trusts exactly the keys in the build key file.
 */

use App\Modules\Licensing\LicenseState;
use App\Modules\Licensing\LicenseVerifier;

describe('licensing service provider', function () {
    it('trusts exactly the keys in the build key file', function () {
        // Arrange
        $file = json_decode((string) file_get_contents(resource_path('licensing/public-keys.json')), true);

        // Act
        $trusted = config('licensing.public_keys');

        // Assert
        expect($trusted)->toBe($file);
    });

    it('gives the application one verifier built from those keys', function () {
        // Arrange
        config(['licensing.public_keys' => []]);

        // Act
        $check = app(LicenseVerifier::class)->verify('{"payload": {"key_id": "k-any"}, "signature": "x"}', now());

        // Assert
        expect($check->message)->toBe('The license is signed with an unknown key.')
            ->and(app(LicenseVerifier::class))->toBe(app(LicenseVerifier::class));
    });

    it('gives the application one license state that reads the installed license file', function () {
        // Arrange
        config(['licensing.license_file' => storage_path('framework/testing/no-such-license.json')]);
        $this->app->forgetInstance(LicenseState::class);

        // Act
        $check = app(LicenseState::class)->check();

        // Assert
        expect($check->message)->toBe('No license is installed.')
            ->and(app(LicenseState::class))->toBe(app(LicenseState::class));
    });
});
