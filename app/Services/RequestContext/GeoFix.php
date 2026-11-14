<?php

namespace App\Services\RequestContext;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A device-reported position from the X-Geo header ("lat,lon,accuracy[,unix-time]"),
 * sent only after the user allowed location access. Anything malformed,
 * out of range or older than MAX_AGE_MINUTES is ignored.
 */
final readonly class GeoFix
{
    public const int MAX_AGE_MINUTES = 15;

    public const float MAX_ACCURACY_METERS = 100_000;

    private function __construct(
        public float $latitude,
        public float $longitude,
        public float $accuracyMeters,
    ) {}

    public static function fromHeader(?string $header, CarbonInterface $now): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $header));
        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }
        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                return null;
            }
        }

        [$latitude, $longitude, $accuracy] = array_map('floatval', array_slice($parts, 0, 3));
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
            || $accuracy < 0 || $accuracy > self::MAX_ACCURACY_METERS) {
            return null;
        }

        if (isset($parts[3])) {
            $takenAt = CarbonImmutable::createFromTimestamp((int) $parts[3]);
            if ($takenAt->lt($now->subMinutes(self::MAX_AGE_MINUTES)) || $takenAt->gt($now->addMinute())) {
                return null;
            }
        }

        return new self($latitude, $longitude, $accuracy);
    }
}
