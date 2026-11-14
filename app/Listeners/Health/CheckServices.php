<?php

namespace App\Listeners\Health;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Events\DiagnosingHealth;
use RuntimeException;
use Throwable;

/**
 * Adds the services GasFlow cannot work without to Laravel's /up health check
 * (load balancers, uptime monitors). /up stays 200 only when PostgreSQL and
 * Redis answer; otherwise Laravel answers 500 "down".
 *
 * The thrown message is generic, so the public endpoint never shows hosts or
 * driver errors; the original error is attached as the previous exception and
 * reaches the logs. A locked license does not make the app unhealthy: it is
 * running, in read-only mode.
 */
final readonly class CheckServices
{
    public function __construct(
        private DatabaseManager $database,
        private Redis $redis,
    ) {}

    public function handle(DiagnosingHealth $event): void
    {
        try {
            $this->database->connection()->select('select 1');
        } catch (Throwable $exception) {
            throw new RuntimeException('The database does not answer.', previous: $exception);
        }

        try {
            $this->redis->connection()->ping();
        } catch (Throwable $exception) {
            throw new RuntimeException('Redis does not answer.', previous: $exception);
        }
    }
}
