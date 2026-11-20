<?php

/**
 * Migration group 16: the permission catalogue every environment has (owner
 * decision D53, design V7.26; design SQL tests 193 and 194).
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
