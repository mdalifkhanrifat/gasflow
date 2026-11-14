<?php

namespace Tests\Support;

use App\Modules\Licensing\Issuing\LicenseSigner;
use App\Modules\Licensing\Issuing\SigningKey;
use App\Modules\Licensing\LicenseState;
use App\Modules\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;

/**
 * Installs a license for the application under test, signed with a key pair
 * generated once per test run. The owner's key is never needed and no key is
 * stored in the repository. The application's own verifier and its trusted
 * key file stay untouched; only the LicenseState instance is replaced.
 */
final class TestLicense
{
    public const string KEY_ID = 'k-test-run';

    private static ?SigningKey $key = null;

    /**
     * An active development license (from 30 days ago, for a year), unless the
     * overrides say otherwise. With $trusted false the app does not trust the
     * signing key, as with a license signed by someone else.
     *
     * @param  array<string, mixed>  $overrides  payload fields
     */
    public static function install(Application $app, array $overrides = [], bool $trusted = true): void
    {
        $today = CarbonImmutable::today();

        $payload = LicenseFixtures::payload([
            'license_no' => 'DEV-TEST',
            'licensee' => 'GasFlow tests',
            'edition' => 'development',
            'domains' => ['localhost'],
            'valid_from' => $today->subDays(30)->toDateString(),
            'valid_until' => $today->addYear()->toDateString(),
            ...$overrides,
        ]);

        file_put_contents(self::file(), LicenseSigner::sign($payload, self::key()));
        self::bind($app, $trusted);
    }

    /**
     * Dates of a license that ended 5 days ago with 14 days of grace.
     *
     * @return array<string, mixed>
     */
    public static function inGrace(): array
    {
        return self::endedDaysAgo(5);
    }

    /**
     * Dates of a license whose 14 days of grace ended 6 days ago.
     *
     * @return array<string, mixed>
     */
    public static function expired(): array
    {
        return self::endedDaysAgo(20);
    }

    public static function remove(Application $app): void
    {
        self::deleteFile();
        self::bind($app, trusted: true);
    }

    public static function deleteFile(): void
    {
        if (is_file(self::file())) {
            unlink(self::file());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function endedDaysAgo(int $days): array
    {
        $today = CarbonImmutable::today();

        return [
            'valid_from' => $today->subYear()->toDateString(),
            'valid_until' => $today->subDays($days)->toDateString(),
            'offline_grace_days' => 14,
        ];
    }

    private static function bind(Application $app, bool $trusted): void
    {
        $keys = $trusted ? [self::KEY_ID => self::key()->publicKeyBase64()] : [];

        $app->instance(LicenseState::class, new LicenseState(new LicenseVerifier($keys), self::file()));
    }

    private static function key(): SigningKey
    {
        return self::$key ??= SigningKey::generate(self::KEY_ID);
    }

    private static function file(): string
    {
        return storage_path('framework/testing/license-'.getmypid().'.json');
    }
}
