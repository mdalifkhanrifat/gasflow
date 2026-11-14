<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Test-only table (never in production) shaped like the design's tables with a
 * public id (users, buildings, invoices, ...): BIGINT primary key plus
 * `public_id CHAR(26) NOT NULL UNIQUE` filled by the application. It stays
 * outside the lifecycle standard on purpose, so it never shows up in the
 * lifecycle and recycle-bin tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE public_id_test_records (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              name TEXT NOT NULL,
              created_at TIMESTAMPTZ,
              updated_at TIMESTAMPTZ
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS public_id_test_records');
    }
};
