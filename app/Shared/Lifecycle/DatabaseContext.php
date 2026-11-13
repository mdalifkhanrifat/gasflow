<?php

namespace App\Shared\Lifecycle;

use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * The request-scoped values the database triggers read (app.user_id,
 * app.organization_id, ...), applied transaction-locally with set_config(..., true),
 * so they disappear at COMMIT / ROLLBACK and never leak to the next request or job.
 *
 * HTTP requests get it through the LifecycleContext middleware. Queued jobs and
 * console commands call run() with the values of the request that started them.
 *
 * app.maintenance_override can never be set from here (design 04, maintenance contract).
 */
final class DatabaseContext
{
    /**
     * The only keys the app may set (each becomes app.<key>).
     *
     * @var list<string>
     */
    public const array KEYS = [
        'user_id', 'organization_id', 'lifecycle_reason',
        'session_id', 'device_id', 'request_id', 'client_type', 'device_kind',
        'ip', 'ip_country', 'ip_city', 'geo_lat', 'geo_lon', 'geo_accuracy_m', 'geo_source',
    ];

    /** @var array<string, string> */
    private array $values = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function set(string $key, string|int|float|null $value): self
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new InvalidArgumentException("The database context key [{$key}] cannot be set by the application.");
        }

        $this->values[$key] = $value === null ? '' : (string) $value;

        return $this;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     */
    public function merge(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Write every value with one statement, local to the current transaction.
     */
    public function apply(): void
    {
        if ($this->values === []) {
            return;
        }

        $calls = implode(', ', array_fill(0, count($this->values), 'set_config(?, ?, true)'));
        $bindings = [];
        foreach ($this->values as $key => $value) {
            $bindings[] = "app.{$key}";
            $bindings[] = $value;
        }

        $this->connection->select("select {$calls}", $bindings);
    }

    /**
     * Run a callback in one transaction with these values applied (queued jobs,
     * console commands). Commits when the callback returns, rolls back when it throws.
     *
     * @template TResult
     *
     * @param  array<string, string|int|float|null>  $values
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(array $values, Closure $callback): mixed
    {
        $this->merge($values);

        return $this->connection->transaction(function () use ($callback) {
            $this->apply();

            return $callback();
        });
    }
}
