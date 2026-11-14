<?php

/**
 * HasPublicId: a ULID public id next to the BIGINT primary key, used in URLs
 * and never changed once saved.
 */

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Fixtures\Models\PublicIdTestRecord;

describe('creating a record', function () {
    it('fills the public id with a ulid', function () {
        // Arrange
        $record = new PublicIdTestRecord(['name' => 'Rupayan Tower']);

        // Act
        $record->save();

        // Assert
        expect(Str::isUlid($record->public_id))->toBeTrue()
            ->and($record->fresh()?->public_id)->toBe($record->public_id);
    });

    it('keeps the bigint id as the primary key', function () {
        // Arrange
        $record = new PublicIdTestRecord(['name' => 'Rupayan Tower']);

        // Act
        $record->save();

        // Assert
        expect($record->getKey())->toBeInt()
            ->and($record->getKeyType())->toBe('int')
            ->and($record->getIncrementing())->toBeTrue();
    });

    it('gives every record its own public id', function () {
        // Arrange
        $first = PublicIdTestRecord::create(['name' => 'Rupayan Tower']);

        // Act
        $second = PublicIdTestRecord::create(['name' => 'Concord Palace']);

        // Assert
        expect($first->public_id)->not->toBe($second->public_id);
    });

    it('keeps a public id that was set before saving', function () {
        // Arrange
        $publicId = strtolower((string) Str::ulid());

        // Act
        $record = PublicIdTestRecord::create(['name' => 'Imported', 'public_id' => $publicId]);

        // Assert
        expect($record->public_id)->toBe($publicId);
    });
});

describe('changing a record', function () {
    it('refuses to change the public id once it is saved', function () {
        // Arrange
        $record = PublicIdTestRecord::create(['name' => 'Rupayan Tower']);
        $record->public_id = strtolower((string) Str::ulid());

        // Act & Assert
        expect(fn () => $record->save())->toThrow(LogicException::class, 'A public id never changes once it is saved.');
    });

    it('still saves other changes', function () {
        // Arrange
        $record = PublicIdTestRecord::create(['name' => 'Rupayan Tower']);

        // Act
        $record->update(['name' => 'Rupayan Tower 2']);

        // Assert
        expect($record->fresh()?->name)->toBe('Rupayan Tower 2');
    });
});

describe('urls', function () {
    beforeEach(function () {
        Route::middleware('web')->get('/_public-id-test/{record}', fn (PublicIdTestRecord $record) => $record->name);
    });

    it('uses the public id as the route key', function () {
        // Arrange
        $record = PublicIdTestRecord::create(['name' => 'Rupayan Tower']);

        // Act
        $routeKey = $record->getRouteKey();

        // Assert
        expect($routeKey)->toBe($record->public_id);
    });

    it('finds a record by its public id', function () {
        // Arrange
        $record = PublicIdTestRecord::create(['name' => 'Rupayan Tower']);

        // Act
        $response = $this->get("/_public-id-test/{$record->public_id}");

        // Assert
        $response->assertOk()->assertSee('Rupayan Tower');
    });

    it('does not find a record by its internal id', function () {
        // Arrange
        $record = PublicIdTestRecord::create(['name' => 'Rupayan Tower']);

        // Act
        $response = $this->get("/_public-id-test/{$record->id}");

        // Assert
        $response->assertNotFound();
    });

    it('answers 404 for a public id that is not a ulid', function () {
        // Arrange: no record needed

        // Act
        $response = $this->get('/_public-id-test/not-a-ulid');

        // Assert
        $response->assertNotFound();
    });
});
