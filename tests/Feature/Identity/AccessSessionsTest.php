<?php

/**
 * access_sessions and the live user counts (design V7.13; design SQL tests
 * 61, 62 and 64).
 */

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

/**
 * @return object{active_users: int, active_sessions: int}|null
 */
function countRow(int $organizationId, ?string $clientType, ?string $deviceKind, ?string $city): ?object
{
    return DB::table('v_active_user_counts')
        ->where('organization_id', $organizationId)
        ->where(fn ($q) => $clientType === null ? $q->whereNull('client_type') : $q->where('client_type', $clientType))
        ->where(fn ($q) => $deviceKind === null ? $q->whereNull('device_kind') : $q->where('device_kind', $deviceKind))
        ->where(fn ($q) => $city === null ? $q->whereNull('city') : $q->where('city', $city))
        ->first();
}

describe('access sessions', function () {
    it('refuse a GPS latitude without a longitude', function () {
        // Arrange
        $row = ['geo_lat' => 23.7];

        // Act & Assert
        expect(fn () => TestRecords::accessSession(overrides: $row))->toThrow(QueryException::class, 'access_sessions_check');
    });

    it('need a reason when they end', function () {
        // Arrange
        $row = ['ended_at' => now()];

        // Act & Assert
        expect(fn () => TestRecords::accessSession(overrides: $row))->toThrow(QueryException::class, 'access_sessions_check1');
    });

    it('are stamped on every change made during them', function () {
        // Arrange
        $session = TestRecords::accessSession();
        setDatabaseContext(['session_id' => $session]);

        // Act
        $organizationId = TestRecords::organization();

        // Assert
        expect(DB::table('organizations')->where('id', $organizationId)->value('created_session_id'))->toBe($session);
    });

    it('refuse a change stamped with a session that does not exist', function () {
        // Arrange
        setDatabaseContext(['session_id' => 999999]);

        // Act & Assert
        expect(fn () => TestRecords::organization())->toThrow(QueryException::class, 'fk_organizations_created_session_id');
    });

    it('are not copied into the activity trail themselves', function () {
        // Arrange
        $userId = User::factory()->create()->id;

        // Act
        $session = TestRecords::accessSession($userId);

        // Assert
        expect(DB::table('data_change_logs')->where(['entity_name' => 'access_sessions', 'record_key' => (string) $session])->exists())
            ->toBeFalse();
    });
});

describe('row-level security', function () {
    it('shows a user their own sessions', function () {
        // Arrange
        $userId = User::factory()->create()->id;
        TestRecords::accessSession($userId, ['organization_id' => TestRecords::organization()]);
        setDatabaseContext(['user_id' => $userId]);
        useAppRole();

        // Act
        $visible = DB::table('access_sessions')->where('user_id', $userId)->count();

        // Assert
        expect($visible)->toBe(1);
    });

    it('hides the sessions of other users in other organizations', function () {
        // Arrange
        $other = User::factory()->create()->id;
        TestRecords::accessSession($other, ['organization_id' => TestRecords::organization()]);
        setDatabaseContext(['user_id' => User::factory()->create()->id, 'organization_id' => TestRecords::organization()]);
        useAppRole();

        // Act
        $visible = DB::table('access_sessions')->where('user_id', $other)->count();

        // Assert
        expect($visible)->toBe(0);
    });
});

describe('live user counts', function () {
    beforeEach(function () {
        $this->organizationId = TestRecords::organization(['code' => 'ACT']);
        TestRecords::accessSession(overrides: ['organization_id' => $this->organizationId, 'client_type' => 'pwa', 'device_kind' => 'mobile', 'ip_city' => 'Dhaka']);
        TestRecords::accessSession(overrides: ['organization_id' => $this->organizationId, 'client_type' => 'browser', 'device_kind' => 'desktop', 'ip_city' => 'Dhaka']);
        TestRecords::accessSession(overrides: ['organization_id' => $this->organizationId, 'client_type' => 'browser', 'device_kind' => 'desktop', 'ended_at' => now(), 'end_reason' => 'logout']);
        setDatabaseContext(['organization_id' => $this->organizationId, 'user_id' => 0]);
    });

    it('counts active users by device kind', function () {
        // Arrange: one phone and one laptop are signed in, one laptop signed out

        // Act
        $mobile = countRow($this->organizationId, null, 'mobile', null);

        // Assert
        expect($mobile?->active_users)->toBe(1);
    });

    it('counts all active users of the organization', function () {
        // Arrange: one phone and one laptop are signed in, one laptop signed out

        // Act
        $total = countRow($this->organizationId, null, null, null);

        // Assert
        expect($total?->active_users)->toBe(2);
    });

    it('counts active users by city', function () {
        // Arrange: both signed-in sessions are in Dhaka

        // Act
        $dhaka = countRow($this->organizationId, null, null, 'Dhaka');

        // Assert
        expect($dhaka?->active_users)->toBe(2);
    });
});
