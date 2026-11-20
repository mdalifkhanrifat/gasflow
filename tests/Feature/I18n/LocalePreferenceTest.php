<?php

use Inertia\Testing\AssertableInertia;

describe('locale preference', function () {
    it('renders the platform default for a new session', function () {
        // Arrange: the request has no saved language.

        // Act
        $response = $this->get(route('home'));

        // Assert
        $response->assertOk()->assertSee('lang="bn"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'bn'));
    });

    it('uses the saved preference for both HTML and Inertia', function () {
        // Arrange
        $this->withSession(['locale' => 'en']);

        // Act
        $response = $this->get(route('home'));

        // Assert
        $response->assertOk()->assertSee('lang="en"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'en'));
    });

    it('saves a supported preference and redirects back', function () {
        // Arrange
        $this->from(route('home'));

        // Act
        $response = $this->post(route('locale.update'), ['locale' => 'en']);

        // Assert
        $response->assertStatus(303)->assertRedirect(route('home'))
            ->assertSessionHas('locale', 'en');
    });

    it('keeps the saved preference when the requested language is unsupported', function () {
        // Arrange
        $this->withSession(['locale' => 'en']);

        // Act
        $response = $this->postJson(route('locale.update'), ['locale' => 'fr']);

        // Assert
        $response->assertUnprocessable()->assertJsonValidationErrors('locale')
            ->assertSessionHas('locale', 'en');
    });
});
