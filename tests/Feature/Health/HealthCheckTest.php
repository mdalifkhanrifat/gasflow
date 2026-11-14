<?php

/**
 * Laravel's /up health check, extended with PostgreSQL and Redis
 * (App\Listeners\Health\CheckServices).
 */

use Illuminate\Support\Facades\Redis;
use Tests\Support\TestLicense;

beforeEach(function () {
    // Production behaviour: the health check answers instead of showing the error page.
    config(['app.debug' => false]);
    $this->defaultConnection = config('database.default');
});

afterEach(function () {
    config(['database.default' => $this->defaultConnection]);
    Redis::purge();
});

/**
 * Point a connection at a port where nothing listens.
 */
function breakConnection(string $config): void
{
    config([$config.'.port' => 1]);
}

describe('health check', function () {
    it('reports up when the database and redis answer', function () {
        // Arrange: the test stack runs PostgreSQL and Redis

        // Act
        $response = $this->getJson('/up');

        // Assert
        $response->assertOk()->assertExactJson(['status' => 'up']);
    });

    it('reports down when the database does not answer', function () {
        // Arrange
        config(['database.connections.pgsql_unreachable' => [...config('database.connections.pgsql'), 'port' => 1, 'connect_timeout' => 1]]);
        config(['database.default' => 'pgsql_unreachable']);

        // Act
        $response = $this->getJson('/up');

        // Assert
        $response->assertStatus(500)->assertExactJson(['status' => 'down']);
    });

    it('reports down when redis does not answer', function () {
        // Arrange
        breakConnection('database.redis.default');
        Redis::purge();

        // Act
        $response = $this->getJson('/up');

        // Assert
        $response->assertStatus(500)->assertExactJson(['status' => 'down']);
    });

    it('never shows the underlying error on the public page', function () {
        // Arrange
        breakConnection('database.redis.default');
        Redis::purge();

        // Act
        $response = $this->get('/up');

        // Assert
        $response->assertStatus(500)
            ->assertSee('experiencing problems')
            ->assertDontSee('Connection refused')
            ->assertDontSee((string) config('database.redis.default.host'));
    });

    it('stays up while the license is locked', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $response = $this->getJson('/up');

        // Assert
        $response->assertOk();
    });
});
