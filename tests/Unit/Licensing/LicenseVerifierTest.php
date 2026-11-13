<?php

/**
 * Ed25519 license verification (design 09 §3, §8). Every test signs with a key
 * pair generated on the spot; no key is stored in the repository for this.
 */

use App\Modules\Licensing\CanonicalJson;
use App\Modules\Licensing\LicenseStatus;
use App\Modules\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;

/**
 * @return array{public: string, secret: string}
 */
function licenseKeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        'secret' => sodium_crypto_sign_secretkey($pair),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function licensePayload(array $overrides = []): array
{
    return [
        'license_no' => 'LIC-00081',
        'licensee' => 'Northstar Gas Services',
        'edition' => 'self_hosted',
        'domains' => ['gas.northstar.example'],
        'max_activations' => 1,
        'limits' => ['organizations' => 1, 'buildings' => 10, 'flats' => 600, 'users' => 50],
        'features' => ['module.billing' => true, 'module.system_setup' => false],
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'offline_grace_days' => 14,
        'key_id' => 'k-test',
        'issued_at' => '2026-01-01T00:00:00Z',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function signLicense(array $payload, string $secretKey): string
{
    $signature = sodium_crypto_sign_detached(CanonicalJson::encode($payload), $secretKey);

    return json_encode(['payload' => $payload, 'signature' => base64_encode($signature)], JSON_THROW_ON_ERROR);
}

function at(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

beforeEach(function () {
    $this->keys = licenseKeyPair();
    $this->verifier = new LicenseVerifier(['k-test' => $this->keys['public']]);
});

describe('signature', function () {
    it('accepts a license signed with a trusted key', function () {
        // Arrange
        $document = signLicense(licensePayload(), $this->keys['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2026-06-01'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Active)
            ->and($check->license?->licenseNo)->toBe('LIC-00081');
    });

    it('refuses a payload changed after signing', function () {
        // Arrange
        $document = json_decode(signLicense(licensePayload(), $this->keys['secret']), true);
        $document['payload']['limits']['flats'] = 60000;

        // Act
        $check = $this->verifier->verify(json_encode($document), at('2026-06-01'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Invalid)
            ->and($check->message)->toBe('The license signature does not match.')
            ->and($check->license)->toBeNull();
    });

    it('refuses a license signed with a key it does not trust', function () {
        // Arrange
        $foreign = licenseKeyPair();
        $document = signLicense(licensePayload(['key_id' => 'k-foreign']), $foreign['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2026-06-01'));

        // Assert
        expect($check->message)->toBe('The license is signed with an unknown key.');
    });

    it('refuses a license that names a trusted key it was not signed with', function () {
        // Arrange
        $foreign = licenseKeyPair();
        $document = signLicense(licensePayload(), $foreign['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2026-06-01'));

        // Assert
        expect($check->message)->toBe('The license signature does not match.');
    });

    it('does not depend on the order of the payload keys', function () {
        // Arrange
        $payload = licensePayload();
        $signed = json_decode(signLicense($payload, $this->keys['secret']), true);
        $reordered = ['payload' => array_reverse($payload, true), 'signature' => $signed['signature']];

        // Act
        $check = $this->verifier->verify(json_encode($reordered), at('2026-06-01'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Active);
    });

    it('refuses a signature that is not a base64 Ed25519 signature', function (string $signature) {
        // Arrange
        $document = json_encode(['payload' => licensePayload(), 'signature' => $signature]);

        // Act
        $check = $this->verifier->verify($document, at('2026-06-01'));

        // Assert
        expect($check->message)->toBe('The license signature does not match.');
    })->with(['not base64' => '***', 'too short' => base64_encode('short'), 'empty' => '']);

    it('ignores a trusted key entry that is not an Ed25519 public key', function () {
        // Arrange
        $verifier = new LicenseVerifier(['k-test' => base64_encode('not 32 bytes')]);
        $document = signLicense(licensePayload(), $this->keys['secret']);

        // Act
        $check = $verifier->verify($document, at('2026-06-01'));

        // Assert
        expect($check->message)->toBe('The license is signed with an unknown key.');
    });
});

describe('document', function () {
    it('refuses text that is not JSON', function () {
        // Act
        $check = $this->verifier->verify('not json', at('2026-06-01'));

        // Assert
        expect($check->message)->toBe('The license is not valid JSON.');
    });

    it('refuses a document without a payload object and a signature', function (string $document) {
        // Act
        $check = $this->verifier->verify($document, at('2026-06-01'));

        // Assert
        expect($check->message)->toBe('The license needs a payload object and a signature.');
    })->with([
        'no signature' => '{"payload": {"key_id": "k-test"}}',
        'payload is a list' => '{"payload": ["k-test"], "signature": "x"}',
        'not an object' => '"license"',
    ]);
});

describe('payload', function () {
    it('refuses a signed payload with a malformed field', function (array $overrides, string $field) {
        // Arrange
        $document = signLicense(licensePayload($overrides), $this->keys['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2026-06-01'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Invalid)
            ->and($check->message)->toBe("The license field [{$field}] is malformed.");
    })->with([
        'missing license number' => [['license_no' => null], 'license_no'],
        'unknown edition' => [['edition' => 'pirate'], 'edition'],
        'no domains' => [['domains' => []], 'domains'],
        'zero flats' => [['limits' => ['flats' => 0]], 'limits.flats'],
        'unknown limit' => [['limits' => ['meters' => 5]], 'limits.meters'],
        'feature not boolean' => [['features' => ['module.billing' => 'yes']], 'features.module.billing'],
        'grace too long' => [['offline_grace_days' => 91], 'offline_grace_days'],
        'impossible date' => [['valid_from' => '2026-02-30'], 'valid_from'],
        'ends before it starts' => [['valid_until' => '2025-12-31'], 'valid_until is before valid_from'],
        'bad issue time' => [['issued_at' => 'yesterday'], 'issued_at'],
    ]);

    it('treats a missing limit as unlimited', function () {
        // Arrange
        $document = signLicense(licensePayload(['limits' => ['buildings' => 10]]), $this->keys['secret']);

        // Act
        $license = $this->verifier->verify($document, at('2026-06-01'))->license;

        // Assert
        expect($license?->limit('buildings'))->toBe(10)
            ->and($license?->limit('users'))->toBeNull();
    });

    it('enables only the features switched on', function () {
        // Arrange
        $document = signLicense(licensePayload(), $this->keys['secret']);

        // Act
        $license = $this->verifier->verify($document, at('2026-06-01'))->license;

        // Assert
        expect($license?->hasFeature('module.billing'))->toBeTrue()
            ->and($license?->hasFeature('module.system_setup'))->toBeFalse()
            ->and($license?->hasFeature('module.unknown'))->toBeFalse();
    });
});

describe('dates', function () {
    it('is not valid before valid_from', function () {
        // Arrange
        $document = signLicense(licensePayload(), $this->keys['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2025-12-31 23:59:59'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Invalid)
            ->and($check->message)->toBe('The license is valid from 2026-01-01.');
    });

    it('is active through the last second of valid_until', function () {
        // Arrange
        $document = signLicense(licensePayload(), $this->keys['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2026-12-31 23:59:59'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Active);
    });

    it('is in grace from the next day for offline_grace_days', function (string $time) {
        // Arrange
        $document = signLicense(licensePayload(), $this->keys['secret']);

        // Act
        $check = $this->verifier->verify($document, at($time));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Grace);
    })->with(['first moment' => '2027-01-01 00:00:00', 'last moment' => '2027-01-14 23:59:59']);

    it('expires when the grace period is over and still shows the license', function () {
        // Arrange
        $document = signLicense(licensePayload(), $this->keys['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2027-01-15 00:00:00'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Expired)
            ->and($check->license?->licenseNo)->toBe('LIC-00081');
    });

    it('expires right after valid_until without grace days', function () {
        // Arrange
        $document = signLicense(licensePayload(['offline_grace_days' => 0]), $this->keys['secret']);

        // Act
        $check = $this->verifier->verify($document, at('2027-01-01 00:00:00'));

        // Assert
        expect($check->status)->toBe(LicenseStatus::Expired);
    });
});

describe('license status', function () {
    it('allows normal operation only when active or in grace', function () {
        // Act
        $allowed = array_map(fn (LicenseStatus $status) => $status->allowsOperation(), LicenseStatus::cases());

        // Assert
        expect(array_combine(array_column(LicenseStatus::cases(), 'value'), $allowed))
            ->toBe(['active' => true, 'grace' => true, 'expired' => false, 'invalid' => false]);
    });
});

describe('canonical json', function () {
    it('sorts object keys at every level and keeps lists in order', function () {
        // Act
        $json = CanonicalJson::encode(['b' => ['z' => 1, 'a' => [3, 1]], 'a' => 'গ্যাস/1']);

        // Assert
        expect($json)->toBe('{"a":"গ্যাস/1","b":{"a":[3,1],"z":1}}');
    });
});

describe('trusted keys', function () {
    it('come from the build, never from the environment', function () {
        // Act
        $config = (string) file_get_contents(__DIR__.'/../../../config/licensing.php');

        // Assert
        expect($config)->not->toContain('env(')
            ->and($config)->toContain("resource_path('licensing/public-keys.json')");
    });
});
