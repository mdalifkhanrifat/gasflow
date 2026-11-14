<?php

namespace App\Enums\Licensing;

/**
 * The evaluated state of a license (design 09 §8). Only Active and Grace allow
 * normal operation; the others lock the app into read-only mode.
 * (pending_activation comes with activation, Phase 11.)
 */
enum LicenseStatus: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Invalid = 'invalid';

    public function allowsOperation(): bool
    {
        return $this === self::Active || $this === self::Grace;
    }
}
