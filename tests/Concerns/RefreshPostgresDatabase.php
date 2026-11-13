<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * Refreshes the PostgreSQL test database the same way production runs:
 * migrations use the owner role (pgsql_migrations), and every test runs
 * inside a rolled-back transaction on the restricted runtime role (pgsql).
 */
trait RefreshPostgresDatabase
{
    use RefreshDatabase;

    /**
     * Refuse to wipe anything that is not a dedicated *_testing database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) config('database.connections.pgsql_migrations.database');

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                "Refusing to refresh [{$database}]: tests only run against a *_testing database.",
            );
        }
    }

    /**
     * Options for "migrate:fresh": always as the owner role, plus the
     * test-only fixture tables in tests/Fixtures/migrations.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $seeder = $this->seeder();

        return array_merge(
            [
                '--database' => 'pgsql_migrations',
                '--path' => [database_path('migrations'), base_path('tests/Fixtures/migrations')],
                '--realpath' => true,
                '--drop-views' => true,
                '--drop-types' => $this->shouldDropTypes(),
            ],
            $seeder ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()],
        );
    }
}
