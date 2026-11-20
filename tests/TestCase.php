<?php

namespace Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\TestLicense;

abstract class TestCase extends BaseTestCase
{
    /**
     * The runtime connection "pgsql" logs in as gasflow_test (BYPASSRLS,
     * member of gasflow_app): ordinary tests arrange rows of several
     * organizations without an organization context. Tests of row-level
     * security and of the runtime role call useAppRole() (SET LOCAL ROLE
     * gasflow_app). The real runtime login stays available as "pgsql_app".
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app->make(Repository::class);

        if ($config->get('database.connections.pgsql_test.password') === '') {
            throw new RuntimeException(
                'The tests log in as gasflow_test: set DB_TEST_PASSWORD in .env (make env) and run make db-roles.',
            );
        }

        $config->set('database.connections.pgsql_app', $config->get('database.connections.pgsql'));
        $config->set('database.connections.pgsql', array_merge(
            (array) $config->get('database.connections.pgsql'),
            [
                'username' => $config->get('database.connections.pgsql_test.username'),
                'password' => $config->get('database.connections.pgsql_test.password'),
            ],
        ));
        // A provider may already have opened "pgsql" while booting.
        $app->make('db')->purge('pgsql');

        return $app;
    }

    /**
     * Every test runs through the license gate, like every installation, with
     * an active license signed by a throw-away key. Tests of the locked paths
     * install another one with TestLicense.
     */
    protected function setUp(): void
    {
        parent::setUp();

        TestLicense::install($this->app);
    }

    protected function tearDown(): void
    {
        TestLicense::deleteFile();

        parent::tearDown();
    }
}
