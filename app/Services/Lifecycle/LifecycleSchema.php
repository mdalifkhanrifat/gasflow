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
     * Refuses new children once their parent is closed: an INSERT, or a move
     * to another parent, while the parent has one of the given statuses.
     * Existing children may still change (an invoice of a locked billing
     * cycle can still be paid); use freezeWhenParentClosed() to stop that too.
     *
     *     LifecycleSchema::refuseNewChildrenWhenParentClosed('invoices', parent: 'billing_cycles',
     *         foreignKey: 'billing_cycle_id', closedStatuses: ['approved', 'issued', 'locked']);
     *
     * Calls lifecycle_refuse_children_of_closed_parent(), which creates
     * trg_<table>_closed_parent_insert. Calling it again replaces the rule.
     *
     * @param  list<string>  $closedStatuses  at least one (checked by the database)
     */
    public static function refuseNewChildrenWhenParentClosed(
        string $table,
        string $parent,
        string $foreignKey,
        array $closedStatuses,
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_refuse_children_of_closed_parent(?::text, ?::text, ?::text, array(select jsonb_array_elements_text(?::jsonb)))',
            [$table, $parent, $foreignKey, json_encode($closedStatuses, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Lets a row be written in the given statuses (every status when none
     * are given) only while its parent has one of the required statuses.
     * Checked when the row is created or changes its status or parent.
     *
     *     LifecycleSchema::requireParentStatus('billing_cycles', parent: 'reading_periods',
     *         foreignKey: 'reading_period_id', parentStatuses: ['approved', 'locked'],
     *         whenStatuses: ['calculating', 'review', 'approved', 'issued', 'locked']);
     *
     * Calls lifecycle_require_parent_status(), which creates
     * trg_<table>_parent_<name> (name defaults to the parent table). Calling
     * it again with the same name replaces the rule.
     *
     * @param  list<string>  $parentStatuses  at least one
     * @param  list<string>  $whenStatuses  statuses of the row that need it; empty = all
     */
    public static function requireParentStatus(
        string $table,
        string $parent,
        string $foreignKey,
        array $parentStatuses,
        array $whenStatuses = [],
        ?string $name = null,
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_require_parent_status(?::text, ?::text, ?::text, ?::text, array(select jsonb_array_elements_text(?::jsonb)), array(select jsonb_array_elements_text(?::jsonb)))',
            [$table, $name ?? $parent, $parent, $foreignKey, json_encode($parentStatuses, JSON_THROW_ON_ERROR), json_encode($whenStatuses, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Lets a parent enter the given statuses only when every visible child
     * has one of the allowed statuses.
     *
     *     LifecycleSchema::requireChildrenStatus('billing_cycles', child: 'invoices',
     *         foreignKey: 'billing_cycle_id', whenStatuses: ['cancelled'], childStatuses: ['void']);
     *
     * Calls lifecycle_require_children_status(), which creates
     * trg_<parent>_children_<name> (name defaults to the child table). Calling
     * it again with the same name replaces the rule.
     *
     * @param  list<string>  $whenStatuses  statuses of the parent that need it
     * @param  list<string>  $childStatuses  allowed statuses of the children
     */
    public static function requireChildrenStatus(
        string $parent,
        string $child,
        string $foreignKey,
        array $whenStatuses,
        array $childStatuses,
        ?string $name = null,
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_require_children_status(?::text, ?::text, ?::text, ?::text, array(select jsonb_array_elements_text(?::jsonb)), array(select jsonb_array_elements_text(?::jsonb)))',
            [$parent, $name ?? $child, $child, $foreignKey, json_encode($whenStatuses, JSON_THROW_ON_ERROR), json_encode($childStatuses, JSON_THROW_ON_ERROR)],
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
     * with its own editable statuses. With no editable status the columns are
     * frozen from the first write (a payment allocation never changes; it is
     * reversed instead):
     *
     *     LifecycleSchema::freezeColumns('payment_allocations', ['amount'], editableStatuses: [], name: 'written');
     *
     * @param  list<string>  $columns
     * @param  list<string>  $editableStatuses  empty = frozen once written
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

    /**
     * Freezes some columns of a child once its parent has one of the given
     * statuses: an UPDATE that changes one of them is refused while the
     * row's parent is closed. The child's other columns (its status, what was
     * paid) still change. New children are a separate rule
     * (refuseNewChildrenWhenParentClosed()).
     *
     *     LifecycleSchema::freezeColumnsWithParent('installation_installments', parent: 'installation_agreements',
     *         foreignKey: 'installation_agreement_id', columns: ['amount', 'due_date'],
     *         closedStatuses: ['active', 'completed', 'defaulted', 'cancelled'], name: 'schedule');
     *
     * Calls lifecycle_freeze_columns_with_parent(), which checks the parent,
     * columns and statuses and creates trg_<table>_parent_freeze_<name>.
     * Calling it again with the same name replaces the rule.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $closedStatuses  at least one
     */
    public static function freezeColumnsWithParent(
        string $table,
        string $parent,
        string $foreignKey,
        array $columns,
        array $closedStatuses,
        string $name = 'fields',
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_freeze_columns_with_parent(?::text, ?::text, ?::text, ?::text, array(select jsonb_array_elements_text(?::jsonb)), array(select jsonb_array_elements_text(?::jsonb)))',
            [$table, $name, $parent, $foreignKey, json_encode($columns, JSON_THROW_ON_ERROR), json_encode($closedStatuses, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Lets columns be written once: each may go from NULL to a value, and
     * from then on an UPDATE that changes it is refused (only the
     * maintenance override may still correct it).
     *
     *     LifecycleSchema::writeOnce('notifications', ['sent_at']);
     *
     * Calls the PostgreSQL function lifecycle_write_once(), which checks the
     * table and columns and creates the trigger trg_<table>_write_once_<name>.
     * Calling it again with the same name replaces the rule.
     *
     * @param  list<string>  $columns
     */
    public static function writeOnce(
        string $table,
        array $columns,
        string $name = 'fields',
        ?string $connection = null,
    ): void {
        DB::connection($connection)->statement(
            'select lifecycle_write_once(?::text, ?::text, array(select jsonb_array_elements_text(?::jsonb)))',
            [$table, $name, json_encode($columns, JSON_THROW_ON_ERROR)],
        );
    }
}
