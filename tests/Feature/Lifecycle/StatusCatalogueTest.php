<?php

/**
 * GET /api/v1/statuses: the status catalogue the UI's status badges read.
 */

use Tests\Support\TestLicense;

describe('status catalogue', function () {
    it('returns an entity\'s statuses in display order', function () {
        // Act
        $response = $this->getJson(route('api.statuses.index'));

        // Assert
        $response->assertOk();
        expect(array_keys($response->json('statuses.invoices')))->toBe([
            'draft', 'approved', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'written_off',
        ]);
    });

    it('gives each status its tone and a label in every supported language', function () {
        // Act
        $response = $this->getJson(route('api.statuses.index'));

        // Assert
        $response->assertOk()->assertJsonPath('statuses.invoices.overdue', [
            'tone' => 'danger',
            'label' => ['bn' => 'মেয়াদোত্তীর্ণ বকেয়া', 'en' => 'Overdue'],
        ]);
    });

    it('returns the same labels whatever language the session uses', function () {
        // Arrange
        $this->withSession(['locale' => 'en']);

        // Act
        $response = $this->getJson(route('api.statuses.index'));

        // Assert
        $response->assertOk()->assertJsonPath('statuses.invoices.draft.label', ['bn' => 'খসড়া', 'en' => 'Draft']);
    });

    it('includes the generic set for tables without their own statuses', function () {
        // Act
        $response = $this->getJson(route('api.statuses.index'));

        // Assert: "*" is a literal key here, not a JSON path wildcard.
        $response->assertOk();
        expect($response->json('statuses')['*']['inactive'])->toBe([
            'tone' => 'muted',
            'label' => ['bn' => 'নিষ্ক্রিয়', 'en' => 'Inactive'],
        ]);
    });

    it('stays readable while the license is locked', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $response = $this->getJson(route('api.statuses.index'));

        // Assert
        $response->assertOk()->assertJsonPath('statuses.invoices.paid.tone', 'success');
    });
});
