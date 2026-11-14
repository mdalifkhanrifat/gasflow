<?php

/**
 * The public home page answers through the full web stack (license gate,
 * request context, lifecycle transaction).
 */

use Tests\Support\TestLicense;

describe('home page', function () {
    it('answers with the welcome page', function () {
        // Arrange: TestCase installs an active license

        // Act
        $response = $this->get(route('home'));

        // Assert
        $response->assertOk();
    });

    it('stays readable while the license is locked', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $response = $this->get(route('home'));

        // Assert
        $response->assertOk();
    });
});
