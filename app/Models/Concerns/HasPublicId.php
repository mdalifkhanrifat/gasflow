<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Public identifier for tables with a `public_id CHAR(26)` column (users,
 * organizations, buildings, customers, invoices, payments, financial
 * transactions, licenses).
 *
 * The BIGINT `id` stays the primary key for joins and foreign keys. Only the
 * ULID leaves the application (URLs, API, exports, links sent to customers),
 * so record numbers can be neither guessed nor counted. Laravel's HasUlids
 * fills it on create, and route model binding answers 404 for a malformed one.
 * Once saved it never changes, because outside systems keep referring to it.
 */
trait HasPublicId
{
    use HasUlids;

    public static function bootHasPublicId(): void
    {
        static::updating(function (Model $model): void {
            if ($model->isDirty('public_id')) {
                throw new LogicException('A public id never changes once it is saved.');
            }
        });
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
