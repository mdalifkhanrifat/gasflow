<?php

/**
 * No background work while locked (design 09 §3): workers pause, queued jobs
 * stay on their queue, synchronous jobs are refused, and the scheduler runs
 * only the task that protects the activity trail.
 */

use App\Modules\Licensing\Exceptions\LicenseLocked;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\WorkerOptions;
use Tests\Support\TestLicense;

final class LicensedBackgroundWorkTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
    }
}

beforeEach(function () {
    LicensedBackgroundWorkTestJob::$runs = 0;
});

/**
 * @return list<string> the artisan commands the scheduler would run now
 */
function scheduledCommandsAllowedToRun(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => $event->filtersPass(app()))
        ->map(fn (ScheduledEvent $event) => trim(str((string) $event->command)->afterLast("'artisan'")->value()))
        ->values()
        ->all();
}

describe('queued jobs', function () {
    it('runs a job while the license is active', function () {
        // Arrange: TestCase installs an active license

        // Act
        LicensedBackgroundWorkTestJob::dispatch();

        // Assert
        expect(LicensedBackgroundWorkTestJob::$runs)->toBe(1);
    });

    it('puts a queued job back on its queue when locked', function () {
        // Arrange
        TestLicense::install($this->app, TestLicense::expired());
        $job = (new LicensedBackgroundWorkTestJob)->withFakeQueueInteractions();

        // Act
        app(Dispatcher::class)->dispatchNow($job);

        // Assert
        $job->assertReleased(300);
        expect(LicensedBackgroundWorkTestJob::$runs)->toBe(0);
    });

    it('refuses a synchronous job when locked', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act & Assert
        expect(fn () => LicensedBackgroundWorkTestJob::dispatchSync())->toThrow(LicenseLocked::class);
        expect(LicensedBackgroundWorkTestJob::$runs)->toBe(0);
    });
});

describe('queue workers', function () {
    it('lets workers take jobs while the license is active', function () {
        // Arrange
        $looping = new Looping('redis', 'default', new WorkerOptions);

        // Act
        $answer = app('events')->until($looping);

        // Assert
        expect($answer)->toBeNull();
    });

    it('pauses workers before they take a job when locked', function () {
        // Arrange
        TestLicense::install($this->app, TestLicense::expired());
        $looping = new Looping('redis', 'critical', new WorkerOptions);

        // Act
        $answer = app('events')->until($looping);

        // Assert
        expect($answer)->toBeFalse();
    });
});

describe('scheduler', function () {
    it('runs every task while the license is active', function () {
        // Arrange: TestCase installs an active license

        // Act
        $commands = scheduledCommandsAllowedToRun();

        // Assert
        expect($commands)->toBe(['audit:change-log-partitions', 'audit:geoip-update']);
    });

    it('runs only the change-log partition task when locked', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $commands = scheduledCommandsAllowedToRun();

        // Assert
        expect($commands)->toBe(['audit:change-log-partitions']);
    });
});
