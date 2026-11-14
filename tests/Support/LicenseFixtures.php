<?php

namespace Tests\Support;

use App\Services\Licensing\Issuing\SigningKey;
use App\Services\Licensing\Issuing\SigningKeyFile;

/**
 * License payloads and keys for tests. Keys are generated on the spot; no
 * signing key is ever stored in the repository.
 */
final class LicenseFixtures
{
    public const string PASSPHRASE = 'correct horse battery staple';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
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
     * Seal with fast Argon2id settings; real keys use the moderate defaults.
     */
    public static function seal(SigningKey $key, string $passphrase = self::PASSPHRASE): string
    {
        return SigningKeyFile::seal($key, $passphrase, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
    }
}
