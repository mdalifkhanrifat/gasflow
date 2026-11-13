<?php

namespace App\Modules\Licensing\Console;

use App\Modules\Licensing\Issuing\SigningKey;
use App\Modules\Licensing\Issuing\SigningKeyFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Creates a license signing key pair for the owner. The private key is sealed
 * with the owner's passphrase and written OUTSIDE the project; only the public
 * key enters the repository (resources/licensing/public-keys.json).
 *
 * Run it through "make license-keygen", which mounts the owner's key folder.
 */
final class GenerateSigningKey extends Command
{
    protected $signature = 'license:keygen
        {--dir= : Folder outside the project for the sealed private key}
        {--id= : Key id written into every license it signs, e.g. kdev-2026}';

    protected $description = 'Create a passphrase-sealed license signing key and trust its public key';

    public function handle(): int
    {
        $directory = (string) $this->option('dir');
        $keyId = (string) ($this->option('id') ?? '');
        $publicKeysFile = (string) config('licensing.public_keys_file');

        if (! is_dir($directory) || ! is_writable($directory)) {
            return $this->refuse("The key folder [{$directory}] does not exist or is not writable.");
        }

        if ($this->insideProject($directory)) {
            return $this->refuse('The private key must be stored outside the project, never in the repository.');
        }

        if (preg_match(SigningKey::ID_PATTERN, $keyId) !== 1) {
            return $this->refuse('--id needs 2–39 lowercase letters, digits or dashes, e.g. kdev-2026.');
        }

        $keyFile = rtrim($directory, '/')."/{$keyId}.key.json";
        /** @var array<string, string> $trusted */
        $trusted = json_decode((string) file_get_contents($publicKeysFile), true, flags: JSON_THROW_ON_ERROR);

        if (file_exists($keyFile) || array_key_exists($keyId, $trusted)) {
            return $this->refuse("The key id [{$keyId}] is already used. Choose a new id.");
        }

        if (! $this->input->isInteractive()) {
            return $this->refuse('The passphrase must be typed by the owner: run it interactively.');
        }

        $passphrase = (string) $this->secret('Passphrase that protects the private key (at least '.SigningKeyFile::MIN_PASSPHRASE_LENGTH.' characters)');

        if ((string) $this->secret('Repeat the passphrase') !== $passphrase) {
            return $this->refuse('The passphrases do not match.');
        }

        $key = SigningKey::generate($keyId);

        try {
            $sealed = SigningKeyFile::seal($key, $passphrase);
        } catch (RuntimeException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $previousMask = umask(0077);
        file_put_contents($keyFile, $sealed, LOCK_EX);
        umask($previousMask);
        chmod($keyFile, 0600);

        $trusted[$keyId] = $key->publicKeyBase64();
        ksort($trusted);
        file_put_contents($publicKeysFile, json_encode($trusted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->components->info("Signing key [{$keyId}] created.");
        $this->components->twoColumnDetail('Private key (sealed, keep it safe)', $keyFile);
        $this->components->twoColumnDetail('Public key (now trusted)', $key->publicKeyBase64());
        $this->components->bulletList([
            'Back up the key file and remember the passphrase. Without both, no new license can be signed with this key.',
            'Commit resources/licensing/public-keys.json so every copy of the app trusts this key.',
        ]);

        return self::SUCCESS;
    }

    private function insideProject(string $directory): bool
    {
        $path = (string) realpath($directory);
        $project = (string) realpath(base_path());

        return $path === $project || str_starts_with($path.'/', $project.'/');
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
