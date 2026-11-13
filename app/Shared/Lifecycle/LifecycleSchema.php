<?php

namespace App\Shared\Lifecycle;

use Illuminate\Support\Facades\DB;

/**
 * Migration helper: joins a table to the universal record lifecycle.
 *
 * Every create_* migration ends with one call:
 *
 *     LifecycleSchema::attach('buildings', module: 'buildings', class: RecordClass::Master);
 *
 * It calls the PostgreSQL function lifecycle_attach(), which registers the
 * table in lifecycle_entities, adds the lifecycle columns, checks, live-row
 * index and triggers. Running it again only updates the registered policy.
 *
 * Must run as the owner role (migrations use the pgsql_migrations connection).
 */
final class LifecycleSchema
{
    public static function attach(
        string $table,
        string $module,
        RecordClass $class,
        SoftDeletePolicy $softDelete = SoftDeletePolicy::Allowed,
        HardDeletePolicy $hardDelete = HardDeletePolicy::Forbidden,
        bool $enforceTransitions = false,
        string $defaultStatus = 'active',
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_attach(?::text, ?::text, ?::text, ?::text, ?::text, ?::boolean, ?::text)',
            [
                $table,
                $module,
                $class->value,
                $softDelete->value,
                $hardDelete->value,
                $enforceTransitions,
                $defaultStatus,
            ],
        );
    }
}
