<?php

namespace App\Shared\Lifecycle;

use App\Shared\Lifecycle\Maintenance\MaintenanceOverrideCommand;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the record lifecycle into the application.
 */
class LifecycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh instance per request and per queued job; the shared
        // cache (version key) keeps long-running workers up to date.
        $this->app->scoped(StatusRegistry::class);

        // One context per request / job, shared by every middleware that adds values.
        $this->app->scoped(DatabaseContext::class);
    }

    public function boot(): void
    {
        // Migrations and seeders may change statuses or transitions.
        Event::listen(MigrationsEnded::class, function (): void {
            $this->app->make(StatusRegistry::class)->flush();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([MaintenanceOverrideCommand::class]);
        }
    }
}
