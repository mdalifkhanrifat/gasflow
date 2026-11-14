<?php

namespace App\Services\RequestContext;

/**
 * Where an IP address is, as far as the local GeoIP database knows.
 */
final readonly class IpLocation
{
    public function __construct(
        public ?string $countryCode,
        public ?string $city,
    ) {}
}
