<?php

/**
 * Request context for the database: every web request runs in one transaction
 * with app.user_id (and later organization, device, location) applied locally.
 */

use App\Models\User;
use App\Shared\Lifecycle\DatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Models\LifecycleTestItem;

function databaseSetting(string $key): string
{
    return (string) DB::scalar('select current_setting(?, true)', ["app.{$key}"]);
}

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/_test/context', fn () => [
            'user_id' => databaseSetting('user_id'),
            'transaction_level' => DB::transactionLevel(),
        ]);
        Route::post('/_test/items', fn () => ['id' => LifecycleTestItem::create(['name' => 'from request'])->id]);
        Route::post('/_test/items-then-fail', function () {
            LifecycleTestItem::create(['name' => 'half written']);

            throw new RuntimeException('Something broke after the first write.');
        });
        Route::post('/_test/items-then-invalid', function () {
            LifecycleTestItem::create(['name' => 'half written']);

            throw ValidationException::withMessages(['name' => 'Invalid.']);
        });
    });
});

describe('web requests', function () {
    it('run inside one database transaction', function () {
        // Arrange
        $levelBeforeRequest = DB::transactionLevel();

        // Act
        $response = $this->getJson('/_test/context');

        // Assert
        expect($response->json('transaction_level'))->toBe($levelBeforeRequest + 1);
    });

    it('give the database the authenticated user', function () {
        // Arrange
        $user = User::factory()->create();

        // Act
        $response = $this->actingAs($user)->getJson('/_test/context');

        // Assert
        expect($response->json('user_id'))->toBe((string) $user->id);
    });

    it('leave the user empty for guests', function () {
        // Act
        $response = $this->getJson('/_test/context');

        // Assert
        expect($response->json('user_id'))->toBe('');
    });

    it('stamp every write with the acting user', function () {
        // Arrange
        $user = User::factory()->create();

        // Act
        $id = $this->actingAs($user)->postJson('/_test/items')->json('id');

        // Assert
        expect(LifecycleTestItem::find($id)->created_by)->toBe($user->id);
    });

    it('commit the writes of a successful request', function () {
        // Act
        $this->postJson('/_test/items')->assertOk();

        // Assert
        expect(LifecycleTestItem::count())->toBe(1);
    });

    it('roll back every write when the request fails', function () {
        // Act
        $response = $this->postJson('/_test/items-then-fail');

        // Assert
        expect($response->status())->toBe(500)
            ->and(LifecycleTestItem::count())->toBe(0);
    });

    it('roll back every write when the request ends in a validation error', function () {
        // Act
        $response = $this->postJson('/_test/items-then-invalid');

        // Assert
        expect($response->status())->toBe(422)
            ->and(LifecycleTestItem::count())->toBe(0);
    });
});

describe('DatabaseContext', function () {
    it('refuses to set the maintenance override', function () {
        // Arrange
        $context = app(DatabaseContext::class);

        // Act & Assert
        expect(fn () => $context->set('maintenance_override', 'on'))
            ->toThrow(InvalidArgumentException::class, 'cannot be set by the application');
    });

    it('refuses keys that are not part of the context', function () {
        // Arrange
        $context = app(DatabaseContext::class);

        // Act & Assert
        expect(fn () => $context->set('role', 'admin'))
            ->toThrow(InvalidArgumentException::class, '[role]');
    });

    it('applies all values with one statement', function () {
        // Arrange
        $context = app(DatabaseContext::class)->merge(['user_id' => 7, 'organization_id' => 3, 'client_type' => 'queue']);
        DB::enableQueryLog();

        // Act
        $context->apply();

        // Assert
        expect(DB::getQueryLog())->toHaveCount(1)
            ->and([databaseSetting('user_id'), databaseSetting('organization_id'), databaseSetting('client_type')])
            ->toBe(['7', '3', 'queue']);
    });

    it('runs a job callback with the context applied', function () {
        // Arrange
        $context = app(DatabaseContext::class);

        // Act
        $seenUser = $context->run(['user_id' => 42, 'client_type' => 'queue'], fn () => databaseSetting('user_id'));

        // Assert
        expect($seenUser)->toBe('42');
    });

    it('rolls back a job callback that throws', function () {
        // Arrange
        $context = app(DatabaseContext::class);

        // Act
        rescue(fn () => $context->run(['client_type' => 'queue'], function () {
            LifecycleTestItem::create(['name' => 'half written']);

            throw new RuntimeException('Job failed.');
        }), report: false);

        // Assert
        expect(LifecycleTestItem::count())->toBe(0);
    });
});
