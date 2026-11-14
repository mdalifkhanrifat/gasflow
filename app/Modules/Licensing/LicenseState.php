<?php

namespace App\Modules\Licensing;

use Carbon\CarbonImmutable;

/**
 * The evaluated license of this installation (design 09 §8), shared by the
 * HTTP gate, the queue workers and the scheduler.
 *
 * The result is kept in this process for a minute, so a long-running worker
 * notices an expiry or a renewed license file without reading the file for
 * every job. It is deliberately not put in the shared cache: whoever could
 * write to Redis could otherwise mark the installation as licensed.
 */
final class LicenseState
{
    public const int RECHECK_SECONDS = 60;

    private ?LicenseCheck $check = null;

    private ?CarbonImmutable $checkedAt = null;

    public function __construct(
        private readonly LicenseVerifier $verifier,
        private readonly string $licenseFile,
    ) {}

    public function check(): LicenseCheck
    {
        $now = CarbonImmutable::now();

        if ($this->check === null || $this->checkedAt === null || $this->isStale($now)) {
            $this->check = $this->evaluate($now);
            $this->checkedAt = $now;
        }

        return $this->check;
    }

    public function status(): LicenseStatus
    {
        return $this->check()->status;
    }

    /**
     * Active or in grace: the installation may write, run jobs and scheduled tasks.
     */
    public function allowsOperation(): bool
    {
        return $this->status()->allowsOperation();
    }

    private function isStale(CarbonImmutable $now): bool
    {
        // A clock set back also counts as stale, so an old result never sticks.
        return $now->lessThan($this->checkedAt)
            || $now->greaterThanOrEqualTo($this->checkedAt?->addSeconds(self::RECHECK_SECONDS));
    }

    private function evaluate(CarbonImmutable $now): LicenseCheck
    {
        if (! is_file($this->licenseFile)) {
            return LicenseCheck::invalid('No license is installed.');
        }

        $document = is_readable($this->licenseFile) ? file_get_contents($this->licenseFile) : false;

        if ($document === false) {
            return LicenseCheck::invalid('The license file cannot be read.');
        }

        return $this->verifier->verify($document, $now);
    }
}
