<?php

namespace App\Console\Commands\Licensing;

use App\Enums\Licensing\LicenseEdition;
use App\Services\Licensing\Issuing\LicenseSigner;
use App\Services\Licensing\Issuing\SigningKeyFile;
use App\Services\Licensing\License;
use App\Services\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The owner signs a license with a sealed signing key and the passphrase.
 * Validity, domains, limits and features are chosen at every issue; nothing is
 * fixed in the repository. The result is verified before it is written.
 *
 * Run it through "make license a=..." which mounts the owner's key folder read-only.
 */
final class IssueLicenseCommand extends Command
{
    protected $signature = 'license:issue
        {--keys= : Folder with the sealed signing key files}
        {--key-id= : Which key to sign with (needed when the folder has several)}
        {--licensee= : Who the license is for}
        {--license-no= : License number (default DEV-<timestamp> for development)}
        {--edition=development : development, internal, hosted_saas, self_hosted or trial}
        {--domain=* : Allowed domain; repeat for more (default localhost)}
        {--valid-from= : First valid day, YYYY-MM-DD (default today)}
        {--valid-until= : Last valid day, YYYY-MM-DD}
        {--days= : Or: number of days from valid-from}
        {--grace=14 : Grace days after valid-until (0-90)}
        {--max-activations=1 : How many installations may activate it}
        {--limit=* : A limit, e.g. buildings=10 (organizations, buildings, flats, users)}
        {--feature=* : A feature to enable, e.g. module.billing}
        {--output= : Where to write the license (default: this installation\'s license file)}';

    protected $description = 'Sign a license with the owner\'s signing key';

    public function handle(): int
    {
        $keyFile = $this->keyFile();
        if ($keyFile === null) {
            return self::FAILURE;
        }

        $payload = $this->payload();
        if ($payload === null) {
            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            return $this->refuse('The passphrase must be typed by the owner: run it interactively.');
        }

        try {
            $key = SigningKeyFile::open((string) file_get_contents($keyFile), (string) $this->secret("Passphrase for {$keyFile}"));
        } catch (RuntimeException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $document = LicenseSigner::sign($payload, $key);
        $check = (new LicenseVerifier([$key->keyId => $key->publicKeyBase64()]))->verify($document, CarbonImmutable::now());

        if ($check->license === null) {
            return $this->refuse('The license was not written: '.$check->message);
        }

        $output = (string) ($this->option('output') ?: config('licensing.license_file'));
        if (! is_dir(dirname($output)) && ! mkdir(dirname($output), 0775, true)) {
            return $this->refuse("Cannot create the folder for [{$output}].");
        }
        file_put_contents($output, $document, LOCK_EX);

        $this->report($check->license, $check->status->value, $output);

        if (! array_key_exists($key->keyId, (array) config('licensing.public_keys'))) {
            $this->components->warn("This installation does not trust the key [{$key->keyId}] yet (resources/licensing/public-keys.json).");
        }

        return self::SUCCESS;
    }

    private function keyFile(): ?string
    {
        $directory = rtrim((string) $this->option('keys'), '/');
        $keyId = $this->option('key-id');

        if ($directory === '' || ! is_dir($directory)) {
            $this->refuse('Give the folder with the signing keys: --keys=<folder> (make license mounts it).');

            return null;
        }

        $files = is_string($keyId) ? array_filter(["{$directory}/{$keyId}.key.json"], 'is_file') : (glob("{$directory}/*.key.json") ?: []);

        if (count($files) !== 1) {
            $this->refuse(match (true) {
                is_string($keyId) => "No key file [{$keyId}.key.json] in {$directory}.",
                $files === [] => "No signing key in {$directory} yet. Create one first: make license-keygen a=\"--id=kdev-2026\".",
                default => "Several keys in {$directory}; choose one with --key-id.",
            });

            return null;
        }

        return $files[array_key_first($files)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(): ?array
    {
        $edition = LicenseEdition::tryFrom((string) $this->option('edition'));
        $licensee = trim((string) $this->option('licensee'));
        $licenseNo = (string) ($this->option('license-no') ?: ($edition === LicenseEdition::Development ? 'DEV-'.now()->format('YmdHis') : ''));
        $validFrom = (string) ($this->option('valid-from') ?: now()->toDateString());
        $validUntil = $this->validUntil($validFrom);
        $limits = $this->pairs((array) $this->option('limit'));

        $problem = match (true) {
            $edition === null => 'Unknown --edition.',
            $licensee === '' => 'Give --licensee (who the license is for).',
            $licenseNo === '' => 'Give --license-no for this edition.',
            $validUntil === null => 'Give either --valid-until=YYYY-MM-DD or --days=N (not both).',
            $limits === null => 'Write each --limit as name=number, e.g. buildings=10.',
            default => null,
        };

        if ($problem !== null) {
            $this->refuse($problem);

            return null;
        }

        /** @var list<string> $domains */
        $domains = array_values(array_filter((array) $this->option('domain'))) ?: ['localhost'];

        return [
            'license_no' => $licenseNo,
            'licensee' => $licensee,
            'edition' => $edition->value,
            'domains' => $domains,
            'max_activations' => (int) $this->option('max-activations'),
            'limits' => $limits,
            'features' => array_fill_keys(array_values(array_filter((array) $this->option('feature'))), true),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'offline_grace_days' => (int) $this->option('grace'),
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function validUntil(string $validFrom): ?string
    {
        $until = $this->option('valid-until');
        $days = $this->option('days');

        if (is_string($until) && $until !== '' && ($days === null || $days === '')) {
            return $until;
        }

        if (is_string($days) && ctype_digit($days) && (int) $days > 0 && ($until === null || $until === '')) {
            return CarbonImmutable::parse($validFrom)->addDays((int) $days - 1)->toDateString();
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $pairs
     * @return array<string, int>|null
     */
    private function pairs(array $pairs): ?array
    {
        $result = [];
        foreach ($pairs as $pair) {
            if (! is_string($pair) || preg_match('~\A([a-z_]+)=(\d+)\z~', $pair, $match) !== 1) {
                return null;
            }
            $result[$match[1]] = (int) $match[2];
        }

        return $result;
    }

    private function report(License $license, string $status, string $output): void
    {
        $this->components->info("License {$license->licenseNo} signed with key [{$license->keyId}] and written to {$output}.");
        $this->components->twoColumnDetail('Licensee', $license->licensee);
        $this->components->twoColumnDetail('Edition', $license->edition->value);
        $this->components->twoColumnDetail('Domains', implode(', ', $license->domains));
        $this->components->twoColumnDetail('Valid', "{$license->validFrom->toDateString()} → {$license->validUntil->toDateString()} (+{$license->offlineGraceDays} grace days)");
        $this->components->twoColumnDetail('Status now', $status);
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
