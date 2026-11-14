<?php

/**
 * user_devices: the installed PWA today, Flutter apps later (design V7.10,
 * V7.13; design SQL tests 28-31).
 */

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertDevice(int $userId, array $overrides = []): int
{
    return (int) DB::table('user_devices')->insertGetId([
        'user_id' => $userId,
        'device_uuid' => (string) Str::uuid(),
        'client_type' => 'pwa',
        'platform' => 'android',
        ...$overrides,
    ]);
}

describe('user devices', function () {
    it('register an installed PWA', function () {
        // Arrange
        $userId = User::factory()->create()->id;

        // Act
        $id = insertDevice($userId, ['device_kind' => 'mobile']);

        // Assert
        $device = DB::table('user_devices')->find($id);
        expect([$device->client_type, $device->device_kind, $device->status, $device->is_active])
            ->toBe(['pwa', 'mobile', 'active', true]);
    });

    it('allow one row per device of a user', function () {
        // Arrange
        $userId = User::factory()->create()->id;
        $uuid = (string) Str::uuid();
        insertDevice($userId, ['device_uuid' => $uuid]);

        // Act & Assert
        expect(fn () => insertDevice($userId, ['device_uuid' => $uuid]))
            ->toThrow(QueryException::class, 'user_devices_user_id_device_uuid_key');
    });

    it('accept a Flutter app only on Android or iOS', function () {
        // Arrange
        $userId = User::factory()->create()->id;

        // Act & Assert
        expect(fn () => insertDevice($userId, ['client_type' => 'flutter', 'platform' => 'web']))
            ->toThrow(QueryException::class, 'user_devices_check1');
    });

    it('need an encrypted token for push notifications', function () {
        // Arrange
        $userId = User::factory()->create()->id;

        // Act & Assert
        expect(fn () => insertDevice($userId, ['client_type' => 'flutter', 'platform' => 'ios', 'push_provider' => 'apns']))
            ->toThrow(QueryException::class, 'user_devices_check');
    });

    it('become inactive when revoked', function () {
        // Arrange
        $id = insertDevice(User::factory()->create()->id);

        // Act
        DB::table('user_devices')->where('id', $id)->update(['status' => 'revoked']);

        // Assert
        expect(DB::table('user_devices')->where('id', $id)->value('is_active'))->toBeFalse();
    });

    it('never write the push token into the activity trail', function () {
        // Arrange
        $userId = User::factory()->create()->id;

        // Act
        $id = insertDevice($userId, ['push_provider' => 'webpush', 'push_token_encrypted' => 'eyJpdiI6InNlY3JldCJ9']);

        // Assert
        $values = json_decode((string) DB::table('data_change_logs')
            ->where(['entity_name' => 'user_devices', 'record_key' => (string) $id])
            ->value('new_values'), true);
        expect($values['push_token_encrypted'])->toBe('[redacted]');
    });
});
