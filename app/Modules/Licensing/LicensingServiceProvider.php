<?php

namespace App\Modules\Licensing;

use Illuminate\Support\ServiceProvider;

/**
 * License gate for every installation, hosted or self-hosted (design 09).
 */
class LicensingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LicenseVerifier::class, function (): LicenseVerifier {
            /** @var array<string, string> $keys */
            $keys = (array) config('licensing.public_keys', []);

            return new LicenseVerifier($keys);
        });
    }
}
