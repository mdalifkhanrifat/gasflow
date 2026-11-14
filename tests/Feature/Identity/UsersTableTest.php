<?php

/**
 * Migration group 2 (identity): the users table in the lifecycle standard
 * (design 02 §1, V7.7, V7.13) and the User model on top of it.
 */

use App\Exceptions\Lifecycle\LifecycleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('users table', function () {
    it('is an identity master record whose rows are hidden, never removed', function () {
        // Arrange: migration group 2 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'users')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe(['identity', 'master', 'allowed', 'forbidden']);
    });

    it('keeps microseconds in every timestamp, like the design', function () {
        // Arrange: Schema::defaultTimePrecision(null) in AppServiceProvider

        // Act
        $precisions = DB::table('information_schema.columns')
            ->where('table_name', 'users')
            ->where('data_type', 'timestamp with time zone')
            ->pluck('datetime_precision')
            ->unique()
            ->all();

        // Assert
        expect($precisions)->toBe([6]);
    });

    it('knows the four user statuses', function () {
        // Arrange: migration group 2 has run

        // Act
        $codes = DB::table('status_definitions')->where('entity_name', 'users')->orderBy('sort_order')->pluck('code')->all();

        // Assert
        expect($codes)->toBe(['active', 'invited', 'suspended', 'closed']);
    });
});

describe('a new user', function () {
    it('starts active, with a public id', function () {
        // Arrange
        $user = User::factory()->make();

        // Act
        $user->save();

        // Assert
        expect($user->status)->toBe('active')
            ->and($user->is_active)->toBeTrue()
            ->and(Str::isUlid($user->public_id))->toBeTrue();
    });
});

describe('status changes', function () {
    it('makes a suspended user inactive', function () {
        // Arrange
        $user = User::factory()->create();

        // Act
        $user->transitionTo('suspended', reason: 'Unpaid invoices');

        // Assert
        expect($user->status)->toBe('suspended')
            ->and($user->is_active)->toBeFalse();
    });

    it('refuses a status that is not in the catalogue', function () {
        // Arrange
        $user = User::factory()->create();

        // Act & Assert
        expect(fn () => $user->transitionTo('banned'))->toThrow(LifecycleViolation::class);
    });
});

describe('deleting a user', function () {
    it('hides the user from normal queries', function () {
        // Arrange
        $user = User::factory()->create();

        // Act
        $user->delete();

        // Assert
        expect(User::find($user->id))->toBeNull()
            ->and(User::withTrashed()->find($user->id)?->is_deleted)->toBeTrue();
    });

    it('keeps the row even when it is force deleted', function () {
        // Arrange
        $user = User::factory()->create();

        // Act
        $user->forceDelete();

        // Assert
        expect(DB::table('users')->where('id', $user->id)->value('is_deleted'))->toBeTrue();
    });
});

describe('activity trail', function () {
    it('never records the password or the remember token', function () {
        // Arrange
        $user = User::factory()->make(['password' => 'correct horse battery staple']);

        // Act
        $user->save();

        // Assert
        $values = json_decode((string) DB::table('data_change_logs')
            ->where(['entity_name' => 'users', 'record_key' => (string) $user->id, 'operation' => 'insert'])
            ->value('new_values'), true);
        expect([$values['password'], $values['remember_token'], $values['email']])
            ->toBe(['[redacted]', '[redacted]', $user->email]);
    });
});
