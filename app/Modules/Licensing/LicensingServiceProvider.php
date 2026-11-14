<?php

namespace App\Modules\Licensing;

use App\Modules\Licensing\Console\GenerateSigningKey;
use App\Modules\Licensing\Console\IssueLicense;
use App\Modules\Licensing\Jobs\Middleware\LicensedJob;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Queue\Events\Looping;
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

        $this->app->singleton(LicenseState::class, fn (): LicenseState => new LicenseState(
            $this->app->make(LicenseVerifier::class),
            (string) config('licensing.license_file'),
        ));
    }

    public function boot(): void
    {
        // Every job, queued listener, mail and notification passes the license gate.
        $this->callAfterResolving(Dispatcher::class, function (Dispatcher $bus): void {
            $bus->pipeThrough([LicensedJob::class]);
        });

        // While locked, workers pause before taking a job, so queued jobs stay untouched.
        $this->app->make(Events::class)->listen(
            Looping::class,
            fn (): ?bool => $this->app->make(LicenseState::class)->allowsOperation() ? null : false,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateSigningKey::class, IssueLicense::class]);
        }
    }
}
