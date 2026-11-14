<?php

/**
 * Migration group 5: dynamic catalogues (design 02 §10 and §16, V7.7, V7.10).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('lifecycle registration', function () {
    it('registers each catalogue as configuration of its module', function (string $table, string $module) {
        // Arrange: migration group 5 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe([$module, 'configuration', 'allowed', 'forbidden']);
    })->with([
        ['feature_catalog', 'subscriptions'],
        ['meter_type_catalog', 'metering'],
        ['payment_method_catalog', 'payments'],
        ['integration_providers', 'integrations'],
    ]);
});

describe('seed rows', function () {
    it('contain the codes of the design', function (string $table, string $key, array $codes) {
        // Arrange: migration group 5 has run

        // Act
        $seeded = DB::table($table)->orderBy($key)->pluck($key)->all();

        // Assert
        expect($seeded)->toBe(collect($codes)->sort()->values()->all());
    })->with([
        ['feature_catalog', 'feature_code', ['module.system_setup', 'module.gas_supply', 'module.billing', 'portal.guest_magic_link', 'payments.online_gateway', 'reports.advanced']],
        ['meter_type_catalog', 'code', ['main', 'sub_meter', 'common_area']],
        ['payment_method_catalog', 'code', ['bkash', 'nagad', 'sslcommerz', 'surjopay', 'bank', 'cash', 'other']],
        ['integration_providers', 'code', ['bkash', 'nagad', 'sslcommerz', 'surjopay', 'sms_generic']],
    ]);

    it('have a Bangla text for every row', function (string $table, string $field) {
        // Arrange: migration group 5 has run

        // Act
        $missing = DB::table($table)->whereRaw("coalesce(translations->'bn'->>?, '') = ''", [$field])->count();

        // Assert
        expect($missing)->toBe(0);
    })->with([
        ['feature_catalog', 'description'],
        ['meter_type_catalog', 'name'],
        ['payment_method_catalog', 'name'],
    ]);

    it('point every provider at a driver in the integrations services', function () {
        // Arrange: migration group 5 has run

        // Act
        $classes = DB::table('integration_providers')->pluck('driver_class');

        // Assert
        expect($classes)->each->toStartWith('App\\Services\\Integrations\\Drivers\\');
    });
});

describe('constraints', function () {
    it('refuse translations that are not an object', function (string $table, array $row) {
        // Arrange
        $row['translations'] = '["মিটার"]';

        // Act & Assert
        expect(fn () => DB::table($table)->insert($row))->toThrow(QueryException::class, "ck_{$table}_translations");
    })->with([
        ['feature_catalog', ['feature_code' => 'reports.extra', 'module_code' => 'reporting', 'description' => 'Extra reports']],
        ['meter_type_catalog', ['code' => 'bulk', 'name' => 'Bulk Meter']],
        ['payment_method_catalog', ['code' => 'rocket', 'name' => 'Rocket', 'method_type' => 'wallet']],
    ]);

    it('refuse an unknown payment method type', function () {
        // Arrange
        $row = ['code' => 'cheque', 'name' => 'Cheque', 'method_type' => 'paper'];

        // Act & Assert
        expect(fn () => DB::table('payment_method_catalog')->insert($row))
            ->toThrow(QueryException::class, 'ck_payment_method_catalog_method_type');
    });

    it('refuse an unknown provider type', function () {
        // Arrange
        $row = ['code' => 'fax_box', 'provider_type' => 'fax', 'display_name' => 'Fax', 'driver_class' => 'App\\Services\\Integrations\\Drivers\\FaxDriver'];

        // Act & Assert
        expect(fn () => DB::table('integration_providers')->insert($row))
            ->toThrow(QueryException::class, 'ck_integration_providers_provider_type');
    });

    it('refuse a provider code that is already used', function () {
        // Arrange
        $row = ['code' => 'bkash', 'provider_type' => 'payment', 'display_name' => 'bKash 2', 'driver_class' => 'App\\Services\\Integrations\\Drivers\\BkashPaymentDriver'];

        // Act & Assert
        expect(fn () => DB::table('integration_providers')->insert($row))
            ->toThrow(QueryException::class, 'integration_providers_code_unique');
    });
});

describe('retiring an entry', function () {
    it('hides it instead of removing it, so old records keep their code', function () {
        // Arrange: the seeded common area meter type

        // Act
        DB::table('meter_type_catalog')->where('code', 'common_area')->delete();

        // Assert
        expect(DB::table('meter_type_catalog')->where('code', 'common_area')->first())
            ->is_deleted->toBeTrue()
            ->is_active->toBeFalse();
    });
});
