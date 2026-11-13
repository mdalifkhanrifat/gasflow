<?php

namespace App\Shared\RequestContext;

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

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([UpdateGeoIpDatabase::class]);
        }
    }
}
