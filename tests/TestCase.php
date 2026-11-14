<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestLicense;

abstract class TestCase extends BaseTestCase
{
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
