<?php

use Tests\Support\TestLicense;

describe('locale preference', function () {
    it('renders the platform default for a new session', function () {
        // Arrange: the request has no saved language.

        // Act
        $response = $this->getJson(route('api.locale.show'));

        // Assert
        $response->assertOk()->assertExactJson(['locale' => 'bn']);
    });

    it('returns the saved preference to JSON clients', function () {
        // Arrange
        $this->withSession(['locale' => 'en']);

        // Act
        $response = $this->getJson(route('api.locale.show'));

        // Assert
        $response->assertOk()->assertExactJson(['locale' => 'en']);
    });

    it('saves a supported preference and returns it', function () {
        // Arrange
        $this->withSession(['locale' => 'bn']);

        // Act
        $response = $this->putJson(route('api.locale.update'), ['locale' => 'en']);

        // Assert
        $response->assertOk()->assertExactJson(['locale' => 'en'])
            ->assertSessionHas('locale', 'en');
    });

    it('keeps the saved preference when the requested language is unsupported', function () {
        // Arrange
        $this->withSession(['locale' => 'en']);

        // Act
        $response = $this->putJson(route('api.locale.update'), ['locale' => 'fr']);

        // Assert
        $response->assertUnprocessable()->assertJsonValidationErrors('locale')
            ->assertSessionHas('locale', 'en');
    });

    it('lets the preview read the locale while the license is locked', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $response = $this->getJson(route('api.locale.show'));

        // Assert
        $response->assertOk()->assertExactJson(['locale' => 'bn']);
    });

    it('refuses locale writes while the license is locked', function () {
        // Arrange
        TestLicense::remove($this->app);
        $this->withSession(['locale' => 'bn']);

        // Act
        $response = $this->putJson(route('api.locale.update'), ['locale' => 'en']);

        // Assert
        $response->assertStatus(423)->assertSessionHas('locale', 'bn');
    });
});
