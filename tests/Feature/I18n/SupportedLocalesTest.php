<?php

/**
 * Migration group 3, part 1: supported languages (design V7.10, D11).
 */

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('supported locales', function () {
    it('speaks Bangla by default and English as well', function () {
        // Arrange: migration group 3 has run

        // Act
        $locales = DB::table('supported_locales')->orderBy('sort_order')->get(['code', 'native_name', 'is_default']);

        // Assert
        expect($locales->map(fn ($l) => [$l->code, $l->native_name, $l->is_default])->all())
            ->toBe([['bn', 'বাংলা', true], ['en', 'English', false]]);
    });

    it('allows only one default language', function () {
        // Arrange
        DB::table('supported_locales')->insert(['code' => 'hi', 'name' => 'Hindi', 'native_name' => 'हिन्दी']);

        // Act & Assert
        expect(fn () => DB::table('supported_locales')->where('code', 'hi')->update(['is_default' => true]))
            ->toThrow(QueryException::class, 'uq_supported_locales_default');
    });

    it('refuses a code that is not a language tag', function () {
        // Arrange
        $row = ['code' => 'Bangla', 'name' => 'Bangla', 'native_name' => 'বাংলা'];

        // Act & Assert
        expect(fn () => DB::table('supported_locales')->insert($row))->toThrow(QueryException::class, 'supported_locales_code_check');
    });

    it('is a configuration table in the lifecycle standard', function () {
        // Arrange: migration group 3 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'supported_locales')->first();

        // Assert
        expect([$entity->module, $entity->record_class])->toBe(['shared', 'configuration']);
    });
});

describe('user locale', function () {
    it('accepts a supported language', function () {
        // Arrange
        $user = User::factory()->create();

        // Act
        DB::table('users')->where('id', $user->id)->update(['locale' => 'en']);

        // Assert
        expect(DB::table('users')->where('id', $user->id)->value('locale'))->toBe('en');
    });

    it('refuses a language GasFlow does not speak', function () {
        // Arrange
        $user = User::factory()->create();

        // Act & Assert
        expect(fn () => DB::table('users')->where('id', $user->id)->update(['locale' => 'fr']))
            ->toThrow(QueryException::class, 'users_locale_fkey');
    });
});
