<?php

use App\Enums\Lifecycle\RecordClass;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 5: dynamic catalogues (design 02 §10 and §16, V7.7, V7.10).
 *
 * A new meter type, payment method, integration provider or product feature
 * is data, not a schema change. Codes are API contracts and are never
 * renamed. Names stay English in the base columns; `translations` holds the
 * Bangla names. The FKs that point at these catalogues (meters, payments,
 * gateways, plan features) come with the tables that own them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_catalog', function (Blueprint $table) {
            $table->string('feature_code', 80)->primary();
            $table->string('module_code', 50);
            $table->text('description');
            $table->boolean('is_limitable')->default(false);
            $table->jsonb('translations')->default('{}');
        });

        Schema::create('meter_type_catalog', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->jsonb('translations')->default('{}');
        });

        Schema::create('payment_method_catalog', function (Blueprint $table) {
            $table->string('code', 60)->primary();
            $table->string('name', 100);
            $table->string('method_type', 24);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->jsonb('translations')->default('{}');
        });

        Schema::create('integration_providers', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('code', 60)->unique();
            $table->string('provider_type', 24);
            $table->string('display_name', 120);
            $table->string('driver_class', 255);
            $table->jsonb('capabilities')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE feature_catalog
              ADD CONSTRAINT ck_feature_catalog_translations CHECK (jsonb_typeof(translations) = 'object');
            ALTER TABLE meter_type_catalog
              ADD CONSTRAINT ck_meter_type_catalog_translations CHECK (jsonb_typeof(translations) = 'object');
            ALTER TABLE payment_method_catalog
              ADD CONSTRAINT ck_payment_method_catalog_translations CHECK (jsonb_typeof(translations) = 'object'),
              ADD CONSTRAINT ck_payment_method_catalog_method_type
                CHECK (method_type IN ('cash','bank','gateway','wallet','other'));
            ALTER TABLE integration_providers
              ADD CONSTRAINT ck_integration_providers_provider_type
                CHECK (provider_type IN ('payment','sms','email','storage','identity','other'));
            SQL);

        // Lifecycle policies exactly as design V7.7 (generic status set).
        LifecycleSchema::attach('feature_catalog', module: 'subscriptions', class: RecordClass::Configuration);
        LifecycleSchema::attach('meter_type_catalog', module: 'metering', class: RecordClass::Configuration);
        LifecycleSchema::attach('payment_method_catalog', module: 'payments', class: RecordClass::Configuration);
        LifecycleSchema::attach('integration_providers', module: 'integrations', class: RecordClass::Configuration);

        DB::unprepared(<<<'SQL'
            INSERT INTO feature_catalog(feature_code, module_code, description, translations) VALUES
             ('module.system_setup', 'system_setup', 'Meter, pipeline and building system setup workflows',
              '{"bn":{"description":"মিটার, পাইপলাইন ও ভবনের সিস্টেম সেটআপ"}}'),
             ('module.gas_supply', 'gas_supply', 'Gas supply and vendor operational workflows',
              '{"bn":{"description":"গ্যাস সরবরাহ ও ভেন্ডরের কার্যক্রম"}}'),
             ('module.billing', 'billing', 'Meter reading, invoice and collection workflows',
              '{"bn":{"description":"মিটার রিডিং, বিল ও আদায়"}}'),
             ('portal.guest_magic_link', 'customer_portal', 'Passwordless expiring invoice/payment access',
              '{"bn":{"description":"পাসওয়ার্ড ছাড়া মেয়াদি লিংকে বিল ও পেমেন্ট দেখা"}}'),
             ('payments.online_gateway', 'payments', 'Online gateway collection',
              '{"bn":{"description":"অনলাইন গেটওয়েতে আদায়"}}'),
             ('reports.advanced', 'reporting', 'Advanced operational and financial reports',
              '{"bn":{"description":"উন্নত কার্যক্রম ও আর্থিক রিপোর্ট"}}');

            INSERT INTO meter_type_catalog(code, name, translations) VALUES
             ('main', 'Main Meter', '{"bn":{"name":"মেইন মিটার"}}'),
             ('sub_meter', 'Sub Meter', '{"bn":{"name":"সাব মিটার"}}'),
             ('common_area', 'Common Area Meter', '{"bn":{"name":"কমন এরিয়া মিটার"}}');

            INSERT INTO payment_method_catalog(code, name, method_type, translations) VALUES
             ('bkash', 'bKash', 'wallet', '{"bn":{"name":"বিকাশ"}}'),
             ('nagad', 'Nagad', 'wallet', '{"bn":{"name":"নগদ"}}'),
             ('sslcommerz', 'SSLCommerz', 'gateway', '{"bn":{"name":"এসএসএলকমার্জ"}}'),
             ('surjopay', 'SurjoPay', 'gateway', '{"bn":{"name":"সূর্যপে"}}'),
             ('bank', 'Bank Transfer', 'bank', '{"bn":{"name":"ব্যাংক ট্রান্সফার"}}'),
             ('cash', 'Cash', 'cash', '{"bn":{"name":"নগদ টাকা"}}'),
             ('other', 'Other', 'other', '{"bn":{"name":"অন্যান্য"}}');

            -- Driver classes follow D35 (app/Services/<Domain>); they arrive in Phase 10.
            INSERT INTO integration_providers(code, provider_type, display_name, driver_class, capabilities) VALUES
             ('bkash', 'payment', 'bKash', 'App\Services\Integrations\Drivers\BkashPaymentDriver', '{"payments":true,"refunds":true}'),
             ('nagad', 'payment', 'Nagad', 'App\Services\Integrations\Drivers\NagadPaymentDriver', '{"payments":true}'),
             ('sslcommerz', 'payment', 'SSLCommerz', 'App\Services\Integrations\Drivers\SslCommerzPaymentDriver', '{"payments":true,"refunds":true}'),
             ('surjopay', 'payment', 'SurjoPay', 'App\Services\Integrations\Drivers\SurjoPayPaymentDriver', '{"payments":true}'),
             ('sms_generic', 'sms', 'Generic SMS', 'App\Services\Integrations\Drivers\GenericSmsDriver', '{"sms":true}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM lifecycle_entities
             WHERE entity_name IN ('feature_catalog', 'meter_type_catalog', 'payment_method_catalog', 'integration_providers');
            SQL);

        Schema::dropIfExists('integration_providers');
        Schema::dropIfExists('payment_method_catalog');
        Schema::dropIfExists('meter_type_catalog');
        Schema::dropIfExists('feature_catalog');
    }
};
