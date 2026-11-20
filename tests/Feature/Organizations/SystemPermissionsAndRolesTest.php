<?php

/**
 * Migration group 16: the permission catalogue and the actor roles every
 * environment has (owner decision D53, design V7.26; design SQL tests 193
 * to 195).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('permission catalogue', function () {
    it('holds exactly the permissions the status transitions name', function () {
        // Arrange
        $named = DB::table('status_transitions')->whereNotNull('permission_code')->distinct()->orderBy('permission_code')->pluck('permission_code')->all();

        // Act
        $seeded = DB::table('permissions')->orderBy('code')->pluck('code')->all();

        // Assert
        expect($seeded)->toHaveCount(30)->toBe($named);
    });

    it('describes every permission', function () {
        // Arrange: the catalogue is seeded

        // Act
        $undescribed = DB::table('permissions')->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count();

        // Assert
        expect($undescribed)->toBe(0);
    });

    it('refuses a transition that names an unknown permission', function () {
        // Arrange
        $transition = DB::table('status_transitions')->where('permission_code', 'invoice.issue')->value('id');

        // Act & Assert
        expect(fn () => DB::table('status_transitions')->where('id', $transition)->update(['permission_code' => 'invoice.print_money']))
            ->toThrow(QueryException::class, 'fk_status_transitions_permission_code');
    });

    it('keeps a transition without a permission', function () {
        // Arrange
        $transition = DB::table('status_transitions')->where('permission_code', 'invoice.issue')->value('id');

        // Act
        DB::table('status_transitions')->where('id', $transition)->update(['permission_code' => null]);

        // Assert
        expect(DB::table('status_transitions')->where('id', $transition)->value('permission_code'))->toBeNull();
    });
});

describe('system roles', function () {
    it('holds one role per actor and the scope it works in', function () {
        // Arrange: the roles are seeded

        // Act
        $roles = DB::table('roles')->orderBy('code')->pluck('scope', 'code')->all();

        // Assert
        expect($roles)->toBe([
            'building.caretaker' => 'building',
            'building.manager' => 'building',
            'organization.admin' => 'organization',
            'organization.vendor_staff' => 'organization',
            'platform.super_admin' => 'platform',
        ]);
    });

    it('names every role', function () {
        // Arrange: the roles are seeded

        // Act
        $unnamed = DB::table('roles')->where('name', '')->count();

        // Assert
        expect($unnamed)->toBe(0);
    });
});
