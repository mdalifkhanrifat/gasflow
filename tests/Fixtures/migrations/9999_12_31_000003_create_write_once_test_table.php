<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Test-only table for the generic write-once rule (loaded by
 * RefreshPostgresDatabase, never in production): a delivery's sent time and
 * reference are written once; its note stays editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE lifecycle_test_deliveries (
              id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              sent_at TIMESTAMPTZ,
              reference TEXT,
              note TEXT
            );
            SQL);

        LifecycleSchema::attach('lifecycle_test_deliveries', module: 'testing', class: RecordClass::Log,
            softDelete: SoftDeletePolicy::Protected);
        LifecycleSchema::writeOnce('lifecycle_test_deliveries', ['sent_at', 'reference']);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS lifecycle_test_deliveries;
            DELETE FROM lifecycle_entities WHERE entity_name = 'lifecycle_test_deliveries';
            SQL);
    }
};
