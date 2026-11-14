<?php

namespace App\Modules\Licensing;

use App\Modules\Licensing\Enums\LicenseStatus;

/**
 * The outcome of verifying a license document. The license is set whenever
 * the signature was valid, so an expired license can still be shown.
 */
final readonly class LicenseCheck
{
    public function __construct(
        public LicenseStatus $status,
        public string $message,
        public ?License $license = null,
    ) {}

    public static function invalid(string $message): self
    {
        return new self(LicenseStatus::Invalid, $message);
    }
}
