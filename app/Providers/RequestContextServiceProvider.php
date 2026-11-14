<?php

namespace App\Providers;

use App\Services\RequestContext\DbIpLocator;
use App\Services\RequestContext\IpLocator;
use Illuminate\Support\ServiceProvider;

/**
 * Who / which device / where: the request context written into every change.
 */
class RequestContextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            IpLocator::class,
            fn (): IpLocator => new DbIpLocator((string) config('audit.geoip.database')),
        );
    }
}
