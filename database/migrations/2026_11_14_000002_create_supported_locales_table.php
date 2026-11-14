<?php

use App\Enums\Lifecycle\RecordClass;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration group 3, part 1: the languages GasFlow speaks (design V7.10, D11).
 *
 * Adding a language = one row here plus its lang files. Bangla is the
 * default. Organizations, users, customers, devices and notification
 * templates refer to this table, so it comes before them; bn and en are
 * part of the schema, not seed data, because column defaults point at 'bn'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE supported_locales (
              code VARCHAR(8) PRIMARY KEY CHECK (code ~ '^[a-z]{2}(-[A-Z]{2})?$'),
              name VARCHAR(60) NOT NULL,
              native_name VARCHAR(60) NOT NULL,
              is_default BOOLEAN NOT NULL DEFAULT FALSE,
              sort_order SMALLINT NOT NULL DEFAULT 0
            );
            CREATE UNIQUE INDEX uq_supported_locales_default ON supported_locales(is_default) WHERE is_default;
            SQL);

        LifecycleSchema::attach('supported_locales', module: 'shared', class: RecordClass::Configuration);

        DB::unprepared(<<<'SQL'
            INSERT INTO supported_locales(code, name, native_name, is_default, sort_order)
            VALUES ('bn', 'Bangla', 'বাংলা', TRUE, 10),
                   ('en', 'English', 'English', FALSE, 20);

            -- locale preference chain: user.locale -> organization.default_locale -> platform default
            ALTER TABLE users ADD COLUMN locale VARCHAR(8) REFERENCES supported_locales(code);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users DROP COLUMN IF EXISTS locale;
            DROP TABLE IF EXISTS supported_locales;
            DELETE FROM lifecycle_entities WHERE entity_name = 'supported_locales';
            SQL);
    }
};
