<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshPostgresDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshPostgresDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Set request context values for the current test transaction, the same
 * values Laravel sets per request (app.user_id, app.organization_id, ...).
 * Another connection (the operator's) gets them for its own transaction.
 *
 * @param  array<string, scalar>  $values  keys without the "app." prefix
 */
function setDatabaseContext(array $values, ?ConnectionInterface $connection = null): void
{
    foreach ($values as $key => $value) {
        ($connection ?? DB::connection())->select('select set_config(?, ?, true)', ["app.{$key}", (string) $value]);
    }
}

/**
 * Switch the current test transaction to the real runtime role, gasflow_app:
 * no BYPASSRLS, so row-level security and its organization context apply.
 * The suite itself connects as gasflow_test, which bypasses the policies to
 * arrange rows of several organizations. Ends with the test's transaction.
 */
function useAppRole(): void
{
    DB::statement('SET LOCAL ROLE gasflow_app');
}

/**
 * An organization written on another connection (the operator's), and that
 * connection's transaction switched to its context: under row-level security
 * the operator, too, writes tenant rows only inside an organization.
 */
function operatorOrganization(ConnectionInterface $connection): int
{
    $organizationId = (int) $connection->table('organizations')->insertGetId([
        'public_id' => strtolower((string) Str::ulid()),
        'code' => 'OPS-'.strtoupper(Str::random(6)),
        'name' => 'Operator test organization',
        'organization_type' => 'management',
    ]);
    setDatabaseContext(['organization_id' => $organizationId], $connection);

    return $organizationId;
}
