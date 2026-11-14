<?php

namespace App\Modules\Licensing\Queue;

use App\Modules\Licensing\LicenseLocked;
use App\Modules\Licensing\LicenseState;
use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Command bus pipe for every job, queued listener, mail and notification
 * (design 09 §3: no background work while locked). A queued job goes back on
 * its queue for later, so nothing is lost; a job run synchronously is refused.
 *
 * Workers normally pause before they take a job while locked (the Looping
 * listener in LicensingServiceProvider), so this is the backstop for
 * `queue:work --once` and synchronous dispatch.
 */
final readonly class LicensedJob
{
    public const int RELEASE_DELAY_SECONDS = 300;

    public function __construct(private LicenseState $license) {}

    public function handle(object $command, Closure $next): mixed
    {
        if ($this->license->allowsOperation()) {
            return $next($command);
        }

        $job = get_object_vars($command)['job'] ?? null;

        if ($job instanceof Job && ! $job instanceof SyncJob) {
            $job->release(self::RELEASE_DELAY_SECONDS);

            return null;
        }

        throw new LicenseLocked;
    }
}
