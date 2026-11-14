<?php

/**
 * LicenseState (design 09 §8): reads and verifies the installed license file
 * and keeps the result in the process for a minute.
 */

use App\Enums\Licensing\LicenseStatus;
use App\Services\Licensing\Issuing\LicenseSigner;
use App\Services\Licensing\Issuing\SigningKey;
use App\Services\Licensing\LicenseState;
use App\Services\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Tests\Support\LicenseFixtures;

beforeEach(function () {
    $this->key = SigningKey::generate('k-state');
    $this->file = sys_get_temp_dir().'/gasflow-license-state-'.getmypid().'.json';
    $this->state = new LicenseState(new LicenseVerifier(['k-state' => $this->key->publicKeyBase64()]), $this->file);
    $this->write = fn (array $overrides = []) => file_put_contents(
        $this->file,
        LicenseSigner::sign(LicenseFixtures::payload($overrides), $this->key),
    );

    // The fixture license runs 2026-01-01 … 2026-12-31 with 14 days of grace.
    CarbonImmutable::setTestNow('2026-06-01 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    if (is_file($this->file)) {
        unlink($this->file);
    }
});

describe('license state', function () {
    it('reports an installation without a license file as invalid', function () {
        // Arrange: no file written

        // Act
        $check = $this->state->check();

        // Assert
        expect($check->status)->toBe(LicenseStatus::Invalid)
            ->and($check->message)->toBe('No license is installed.')
            ->and($this->state->allowsOperation())->toBeFalse();
    });

    it('verifies the installed license file', function () {
        // Arrange
        ($this->write)();

        // Act
        $check = $this->state->check();

        // Assert
        expect($check->status)->toBe(LicenseStatus::Active)
            ->and($check->license?->licenseNo)->toBe('LIC-00081')
            ->and($this->state->allowsOperation())->toBeTrue();
    });

    it('allows operation during the grace period', function () {
        // Arrange
        ($this->write)();
        CarbonImmutable::setTestNow('2027-01-10 12:00:00');

        // Act
        $status = $this->state->status();

        // Assert
        expect($status)->toBe(LicenseStatus::Grace)
            ->and($this->state->allowsOperation())->toBeTrue();
    });

    it('locks once the grace period is over', function () {
        // Arrange
        ($this->write)();
        CarbonImmutable::setTestNow('2027-01-20 12:00:00');

        // Act
        $allowed = $this->state->allowsOperation();

        // Assert
        expect($this->state->status())->toBe(LicenseStatus::Expired)
            ->and($allowed)->toBeFalse();
    });

    it('reuses the result for a minute', function () {
        // Arrange
        ($this->write)();
        $this->state->check();
        unlink($this->file);
        CarbonImmutable::setTestNow('2026-06-01 12:00:59');

        // Act
        $status = $this->state->status();

        // Assert
        expect($status)->toBe(LicenseStatus::Active);
    });

    it('reads the file again after a minute', function () {
        // Arrange
        ($this->write)();
        $this->state->check();
        unlink($this->file);
        CarbonImmutable::setTestNow('2026-06-01 12:01:00');

        // Act
        $status = $this->state->status();

        // Assert
        expect($status)->toBe(LicenseStatus::Invalid);
    });

    it('reads the file again when the clock was set back', function () {
        // Arrange
        ($this->write)();
        $this->state->check();
        unlink($this->file);
        CarbonImmutable::setTestNow('2026-06-01 11:59:30');

        // Act
        $status = $this->state->status();

        // Assert
        expect($status)->toBe(LicenseStatus::Invalid);
    });

    it('notices a renewed license without a restart', function () {
        // Arrange
        ($this->write)(['valid_until' => '2026-06-01', 'offline_grace_days' => 0]);
        CarbonImmutable::setTestNow('2026-06-02 12:00:00');
        $this->state->check();
        ($this->write)(['valid_until' => '2027-06-01']);
        CarbonImmutable::setTestNow('2026-06-02 12:01:00');

        // Act
        $status = $this->state->status();

        // Assert
        expect($status)->toBe(LicenseStatus::Active);
    });
});
