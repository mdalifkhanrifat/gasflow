<?php

/**
 * Correlation id: one server-made UUID per request, in the response header,
 * Laravel's Context (logs, queued jobs) and the activity trail.
 */

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Fixtures\Models\LifecycleTestItem;
use Tests\Support\TestLicense;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/_test/request-id', fn () => ['context' => Context::get('request_id')]);
        Route::post('/_test/request-id/items', fn () => ['id' => LifecycleTestItem::create(['name' => 'traced'])->id]);
    });
});

describe('request id', function () {
    it('sends a uuid back in the X-Request-Id header', function () {
        // Arrange: no headers needed

        // Act
        $response = $this->get('/_test/request-id');

        // Assert
        expect(Str::isUuid($response->headers->get(AssignRequestId::HEADER)))->toBeTrue();
    });

    it('gives each request its own id', function () {
        // Arrange
        $first = $this->get('/_test/request-id')->headers->get(AssignRequestId::HEADER);

        // Act
        $second = $this->get('/_test/request-id')->headers->get(AssignRequestId::HEADER);

        // Assert
        expect($second)->not->toBe($first);
    });

    it('ignores an id sent by the client', function () {
        // Arrange
        $forged = (string) Str::uuid7();

        // Act
        $response = $this->withHeaders([AssignRequestId::HEADER => $forged])->get('/_test/request-id');

        // Assert
        expect($response->headers->get(AssignRequestId::HEADER))->not->toBe($forged);
    });

    it('shares the id with logs and queued jobs through the context', function () {
        // Arrange: no headers needed

        // Act
        $response = $this->getJson('/_test/request-id');

        // Assert
        expect($response->json('context'))->toBe($response->headers->get(AssignRequestId::HEADER));
    });

    it('stores the id with every change in the activity trail', function () {
        // Arrange: no headers needed

        // Act
        $response = $this->postJson('/_test/request-id/items');

        // Assert
        $logged = DB::table('data_change_logs')
            ->where(['entity_name' => 'lifecycle_test_items', 'record_key' => (string) $response->json('id')])
            ->value('request_id');
        expect($logged)->toBe($response->headers->get(AssignRequestId::HEADER));
    });

    it('also marks a refused request', function () {
        // Arrange
        TestLicense::remove($this->app);

        // Act
        $response = $this->postJson('/_test/request-id/items');

        // Assert
        $response->assertStatus(423);
        expect(Str::isUuid($response->headers->get(AssignRequestId::HEADER)))->toBeTrue();
    });

    it('also marks a request for a page that does not exist', function () {
        // Arrange: no route matches this path

        // Act
        $response = $this->get('/_test/request-id/no-such-page');

        // Assert
        $response->assertNotFound();
        expect(Str::isUuid($response->headers->get(AssignRequestId::HEADER)))->toBeTrue();
    });
});
