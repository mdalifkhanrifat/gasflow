<?php

/**
 * The owner's license tools: license:keygen (sealed key outside the project,
 * public key trusted) and license:issue (sign a license with the passphrase).
 * Each test works in its own temporary folder and a copy of the trusted keys file.
 */

use App\Enums\Licensing\LicenseStatus;
use App\Services\Licensing\Issuing\SigningKey;
use App\Services\Licensing\Issuing\SigningKeyFile;
use App\Services\Licensing\LicenseVerifier;
use Illuminate\Support\Facades\File;
use Tests\Support\LicenseFixtures;

const KEYGEN_PASSPHRASE_QUESTION = 'Passphrase that protects the private key (at least 12 characters)';

beforeEach(function () {
    $this->folder = sys_get_temp_dir().'/gasflow-license-'.bin2hex(random_bytes(6));
    mkdir($this->folder.'/keys', 0700, true);
    $this->trustedFile = $this->folder.'/public-keys.json';
    file_put_contents($this->trustedFile, "{}\n");
    config(['licensing.public_keys_file' => $this->trustedFile]);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

/**
 * A sealed key in the test key folder, as license:keygen would leave it.
 */
function sealedKeyIn(string $folder, string $keyId = 'kdev-test'): SigningKey
{
    $key = SigningKey::generate($keyId);
    file_put_contents("{$folder}/{$keyId}.key.json", LicenseFixtures::seal($key));

    return $key;
}

describe('license:keygen', function () {
    it('writes a sealed private key the owner can open with the passphrase', function () {
        // Act
        $this->artisan('license:keygen', ['--dir' => $this->folder.'/keys', '--id' => 'kdev-2026'])
            ->expectsQuestion(KEYGEN_PASSPHRASE_QUESTION, LicenseFixtures::PASSPHRASE)
            ->expectsQuestion('Repeat the passphrase', LicenseFixtures::PASSPHRASE)
            ->assertSuccessful();

        // Assert
        $file = $this->folder.'/keys/kdev-2026.key.json';
        expect(fileperms($file) & 0777)->toBe(0600)
            ->and(SigningKeyFile::open((string) file_get_contents($file), LicenseFixtures::PASSPHRASE)->keyId)->toBe('kdev-2026');
    });

    it('trusts the new public key', function () {
        // Act
        $this->artisan('license:keygen', ['--dir' => $this->folder.'/keys', '--id' => 'kdev-2026'])
            ->expectsQuestion(KEYGEN_PASSPHRASE_QUESTION, LicenseFixtures::PASSPHRASE)
            ->expectsQuestion('Repeat the passphrase', LicenseFixtures::PASSPHRASE)
            ->assertSuccessful();

        // Assert
        $trusted = json_decode((string) file_get_contents($this->trustedFile), true);
        $opened = SigningKeyFile::open((string) file_get_contents($this->folder.'/keys/kdev-2026.key.json'), LicenseFixtures::PASSPHRASE);
        expect($trusted)->toBe(['kdev-2026' => $opened->publicKeyBase64()]);
    });

    it('refuses to store the private key inside the project', function () {
        // Act & Assert
        $this->artisan('license:keygen', ['--dir' => storage_path(), '--id' => 'kdev-2026'])
            ->expectsOutputToContain('The private key must be stored outside the project')
            ->assertFailed();
    });

    it('refuses a key id that is already used', function () {
        // Arrange
        file_put_contents($this->trustedFile, json_encode(['kdev-2026' => base64_encode(random_bytes(32))]));

        // Act & Assert
        $this->artisan('license:keygen', ['--dir' => $this->folder.'/keys', '--id' => 'kdev-2026'])
            ->expectsOutputToContain('The key id [kdev-2026] is already used')
            ->assertFailed();
    });

    it('refuses passphrases that do not match', function () {
        // Act
        $this->artisan('license:keygen', ['--dir' => $this->folder.'/keys', '--id' => 'kdev-2026'])
            ->expectsQuestion(KEYGEN_PASSPHRASE_QUESTION, LicenseFixtures::PASSPHRASE)
            ->expectsQuestion('Repeat the passphrase', 'something else entirely')
            ->expectsOutputToContain('The passphrases do not match')
            ->assertFailed();

        // Assert
        expect(glob($this->folder.'/keys/*'))->toBe([]);
    });

    it('refuses to run without someone typing the passphrase', function () {
        // Act & Assert
        $this->artisan('license:keygen', ['--dir' => $this->folder.'/keys', '--id' => 'kdev-2026', '--no-interaction' => true])
            ->expectsOutputToContain('run it interactively')
            ->assertFailed();
    });
});

describe('license:issue', function () {
    it('signs a license with the chosen validity, domains, limits and features', function () {
        // Arrange
        $this->travelTo('2026-11-13 10:00:00');
        $key = sealedKeyIn($this->folder.'/keys');
        $output = $this->folder.'/license.json';

        // Act
        $this->artisan('license:issue', [
            '--keys' => $this->folder.'/keys',
            '--licensee' => 'Alif (development)',
            '--days' => '30',
            '--domain' => ['localhost', 'gasflow.test'],
            '--limit' => ['buildings=5'],
            '--feature' => ['module.billing'],
            '--output' => $output,
        ])
            ->expectsQuestion("Passphrase for {$this->folder}/keys/kdev-test.key.json", LicenseFixtures::PASSPHRASE)
            ->assertSuccessful();

        // Assert
        $check = (new LicenseVerifier(['kdev-test' => $key->publicKeyBase64()]))->verify((string) file_get_contents($output), now());
        expect($check->status)->toBe(LicenseStatus::Active)
            ->and($check->license?->edition->value)->toBe('development')
            ->and($check->license?->validUntil->toDateString())->toBe('2026-12-12')
            ->and($check->license?->domains)->toBe(['localhost', 'gasflow.test'])
            ->and($check->license?->limit('buildings'))->toBe(5)
            ->and($check->license?->hasFeature('module.billing'))->toBeTrue();
    });

    it('writes to this installation\'s license file by default', function () {
        // Arrange
        sealedKeyIn($this->folder.'/keys');
        config(['licensing.license_file' => $this->folder.'/installation/license.json']);

        // Act
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', '--valid-until' => '2027-01-31'])
            ->expectsQuestion("Passphrase for {$this->folder}/keys/kdev-test.key.json", LicenseFixtures::PASSPHRASE)
            ->assertSuccessful();

        // Assert
        expect(json_decode((string) file_get_contents($this->folder.'/installation/license.json'), true)['payload']['valid_until'])
            ->toBe('2027-01-31');
    });

    it('writes nothing when the passphrase is wrong', function () {
        // Arrange
        sealedKeyIn($this->folder.'/keys');
        $output = $this->folder.'/license.json';

        // Act
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', '--days' => '30', '--output' => $output])
            ->expectsQuestion("Passphrase for {$this->folder}/keys/kdev-test.key.json", 'not the passphrase')
            ->expectsOutputToContain('Wrong passphrase')
            ->assertFailed();

        // Assert
        expect(file_exists($output))->toBeFalse();
    });

    it('refuses to sign without someone typing the passphrase', function () {
        // Arrange
        sealedKeyIn($this->folder.'/keys');

        // Act & Assert
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', '--days' => '30', '--no-interaction' => true])
            ->expectsOutputToContain('run it interactively')
            ->assertFailed();
    });

    it('needs exactly one way to say how long the license is valid', function (array $validity) {
        // Arrange
        sealedKeyIn($this->folder.'/keys');

        // Act & Assert
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', ...$validity])
            ->expectsOutputToContain('Give either --valid-until=YYYY-MM-DD or --days=N (not both)')
            ->assertFailed();
    })->with([
        'neither' => [[]],
        'both' => [['--days' => '30', '--valid-until' => '2027-01-31']],
        'zero days' => [['--days' => '0']],
    ]);

    it('asks which key to use when the folder has several', function () {
        // Arrange
        sealedKeyIn($this->folder.'/keys', 'kdev-one');
        sealedKeyIn($this->folder.'/keys', 'kdev-two');

        // Act & Assert
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', '--days' => '30'])
            ->expectsOutputToContain('choose one with --key-id')
            ->assertFailed();
    });

    it('explains how to create a key when the folder has none', function () {
        // Act & Assert
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', '--days' => '30'])
            ->expectsOutputToContain('No signing key in '.$this->folder.'/keys yet. Create one first: make license-keygen')
            ->assertFailed();
    });

    it('does not write a license whose payload the verifier would refuse', function () {
        // Arrange
        sealedKeyIn($this->folder.'/keys');
        $output = $this->folder.'/license.json';

        // Act
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', '--days' => '30', '--limit' => ['meters=5'], '--output' => $output])
            ->expectsQuestion("Passphrase for {$this->folder}/keys/kdev-test.key.json", LicenseFixtures::PASSPHRASE)
            ->expectsOutputToContain('The license field [limits.meters] is malformed')
            ->assertFailed();

        // Assert
        expect(file_exists($output))->toBeFalse();
    });

    it('warns when this installation does not trust the signing key', function () {
        // Arrange
        sealedKeyIn($this->folder.'/keys');
        config(['licensing.public_keys' => []]);

        // Act & Assert
        $this->artisan('license:issue', ['--keys' => $this->folder.'/keys', '--licensee' => 'Alif', '--days' => '30', '--output' => $this->folder.'/license.json'])
            ->expectsQuestion("Passphrase for {$this->folder}/keys/kdev-test.key.json", LicenseFixtures::PASSPHRASE)
            ->expectsOutputToContain('This installation does not trust the key [kdev-test] yet')
            ->assertSuccessful();
    });
});
