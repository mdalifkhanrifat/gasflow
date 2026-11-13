<?php

/**
 * The owner's private signing key at rest: sealed with a passphrase, useless
 * without it, and bound to its key id and public key.
 */

use App\Modules\Licensing\Issuing\LicenseSigner;
use App\Modules\Licensing\Issuing\SigningKey;
use App\Modules\Licensing\Issuing\SigningKeyFile;
use App\Modules\Licensing\LicenseStatus;
use App\Modules\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Tests\Support\LicenseFixtures;

describe('signing key file', function () {
    it('opens with the right passphrase', function () {
        // Arrange
        $key = SigningKey::generate('kdev-test');
        $file = LicenseFixtures::seal($key);

        // Act
        $opened = SigningKeyFile::open($file, LicenseFixtures::PASSPHRASE);

        // Assert
        expect($opened->keyId)->toBe('kdev-test')
            ->and($opened->secretKey)->toBe($key->secretKey);
    });

    it('does not contain the secret key in readable form', function () {
        // Arrange
        $key = SigningKey::generate('kdev-test');

        // Act
        $file = LicenseFixtures::seal($key);

        // Assert
        expect($file)->not->toContain(base64_encode($key->secretKey))
            ->and($file)->not->toContain(LicenseFixtures::PASSPHRASE);
    });

    it('refuses a wrong passphrase', function () {
        // Arrange
        $file = LicenseFixtures::seal(SigningKey::generate('kdev-test'));

        // Act & Assert
        expect(fn () => SigningKeyFile::open($file, 'not the passphrase'))
            ->toThrow(RuntimeException::class, 'Wrong passphrase, or the key file was changed.');
    });

    it('refuses a file whose key id was changed', function () {
        // Arrange
        $file = json_decode(LicenseFixtures::seal(SigningKey::generate('kdev-test')), true);
        $file['key_id'] = 'kprod-2026';

        // Act & Assert
        expect(fn () => SigningKeyFile::open(json_encode($file), LicenseFixtures::PASSPHRASE))
            ->toThrow(RuntimeException::class, 'Wrong passphrase, or the key file was changed.');
    });

    it('refuses a passphrase shorter than twelve characters', function () {
        // Act & Assert
        expect(fn () => LicenseFixtures::seal(SigningKey::generate('kdev-test'), 'short'))
            ->toThrow(RuntimeException::class, 'The passphrase needs at least 12 characters.');
    });

    it('refuses text that is not a key file', function () {
        // Act & Assert
        expect(fn () => SigningKeyFile::open('{"key_id": "x"}', LicenseFixtures::PASSPHRASE))
            ->toThrow(RuntimeException::class, 'This is not a readable signing key file.');
    });
});

describe('signing key', function () {
    it('refuses a public key that does not belong to the secret key', function () {
        // Arrange
        $one = SigningKey::generate('kdev-one');
        $other = SigningKey::generate('kdev-two');

        // Act & Assert
        expect(fn () => new SigningKey('kdev-one', $other->publicKey, $one->secretKey))
            ->toThrow(InvalidArgumentException::class, 'do not belong together');
    });

    it('refuses a key id outside lowercase letters, digits and dashes', function () {
        // Act & Assert
        expect(fn () => SigningKey::generate('../etc/passwd'))
            ->toThrow(InvalidArgumentException::class, 'A key id has 2–39 lowercase letters');
    });
});

describe('license signer', function () {
    it('produces a license the verifier accepts with the matching public key', function () {
        // Arrange
        $key = SigningKey::generate('kdev-test');
        $payload = LicenseFixtures::payload(['key_id' => 'something-else']);

        // Act
        $document = LicenseSigner::sign($payload, $key);

        // Assert
        $check = (new LicenseVerifier(['kdev-test' => $key->publicKeyBase64()]))->verify($document, CarbonImmutable::parse('2026-06-01'));
        expect($check->status)->toBe(LicenseStatus::Active)
            ->and($check->license?->keyId)->toBe('kdev-test');
    });
});
