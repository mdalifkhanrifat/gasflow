<?php

namespace App\Services\Lifecycle;

use App\Enums\Lifecycle\HardDeletePolicy;
use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
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

    /**
     * Freezes the rows of a table once their parent is closed: no insert and
     * no update while the parent has one of the given statuses (only the
     * maintenance override may still correct them).
     *
     *     LifecycleSchema::freezeWhenParentClosed('meter_readings', parent: 'reading_periods',
     *         foreignKey: 'reading_period_id', closedStatuses: ['approved', 'locked', 'cancelled']);
     *
     * Calls the PostgreSQL function lifecycle_freeze_with_parent(), which
     * checks the parent, column and statuses and creates the trigger
     * trg_<table>_closed_parent. Run it after the parent's statuses exist.
     *
     * @param  list<string>  $closedStatuses  at least one (checked by the database)
     */
    public static function freezeWhenParentClosed(
        string $table,
        string $parent,
        string $foreignKey,
        array $closedStatuses,
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_freeze_with_parent(?::text, ?::text, ?::text, array(select jsonb_array_elements_text(?::jsonb)))',
            [$table, $parent, $foreignKey, json_encode($closedStatuses, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Freezes columns once a row leaves its editable statuses: an UPDATE that
     * changes one of them is refused while the row's current status is not
     * editable (only the maintenance override may still correct it).
     *
     *     LifecycleSchema::freezeColumns('tariff_versions', ['rate', 'unit'], editableStatuses: ['draft']);
     *
     * Calls the PostgreSQL function lifecycle_freeze_columns(), which checks
     * the table, columns and statuses and creates the trigger
     * trg_<table>_freeze_<name>. Use a different name for each column group
     * with its own editable statuses.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $editableStatuses
     */
    public static function freezeColumns(
        string $table,
        array $columns,
        array $editableStatuses,
        string $name = 'fields',
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_freeze_columns(?::text, ?::text, array(select jsonb_array_elements_text(?::jsonb)), array(select jsonb_array_elements_text(?::jsonb)))',
            [$table, $name, json_encode($columns, JSON_THROW_ON_ERROR), json_encode($editableStatuses, JSON_THROW_ON_ERROR)],
        );
    }
}
