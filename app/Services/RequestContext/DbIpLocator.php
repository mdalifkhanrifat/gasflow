<?php

namespace App\Services\RequestContext;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use MaxMind\Db\Reader\InvalidDatabaseException;

/**
 * IP → city and country from the local DB-IP City Lite database (MaxMind format).
 * Never calls a remote service. Without the database file it simply knows nothing.
 */
final class DbIpLocator implements IpLocator
{
    private ?Reader $reader = null;

    private bool $opened = false;

    public function __construct(private readonly string $databasePath) {}

    public function locate(string $ip): ?IpLocation
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $reader = $this->reader();
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->city($ip);
        } catch (AddressNotFoundException|InvalidDatabaseException) {
            return null;
        }

        return new IpLocation(countryCode: $record->country->isoCode, city: $record->city->name);
    }

    private function reader(): ?Reader
    {
        if (! $this->opened) {
            $this->opened = true;
            $this->reader = is_file($this->databasePath) ? new Reader($this->databasePath) : null;
        }

        return $this->reader;
    }
}
