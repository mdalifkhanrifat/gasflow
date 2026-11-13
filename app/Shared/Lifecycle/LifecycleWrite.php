<?php

namespace App\Shared\Lifecycle;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Runs a lifecycle write in a savepoint with app.lifecycle_reason set for that
 * write only (the previous value is restored afterwards), so the reason lands in
 * the trails of exactly this change. Database refusals become LifecycleViolation.
 */
final class LifecycleWrite
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     *
     * @throws LifecycleViolation when the database lifecycle rules refuse the write
     */
    public static function withReason(ConnectionInterface $connection, ?string $reason, Closure $write): mixed
    {
        try {
            return $connection->transaction(function () use ($connection, $reason, $write) {
                $previousReason = $connection->scalar("select current_setting('app.lifecycle_reason', true)");
                $connection->select("select set_config('app.lifecycle_reason', ?, true)", [$reason ?? '']);

                $result = $write();

                $connection->select("select set_config('app.lifecycle_reason', ?, true)", [(string) $previousReason]);

                return $result;
            });
        } catch (QueryException $exception) {
            throw LifecycleViolation::fromQueryException($exception) ?? $exception;
        }
    }
}
