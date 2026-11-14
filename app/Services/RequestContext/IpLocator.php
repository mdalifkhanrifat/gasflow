<?php

namespace App\Services\RequestContext;

interface IpLocator
{
    /**
     * The city and country of a public IP address; null when unknown.
     */
    public function locate(string $ip): ?IpLocation;
}
