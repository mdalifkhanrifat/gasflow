<?php

namespace App\Models\Concerns;

use App\Exceptions\Lifecycle\LifecycleViolation;
use App\Services\Lifecycle\LifecycleWrite;
use BackedEnum;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Eloquent side of the universal record lifecycle (08-record-lifecycle-standard.md §5).
 *
 * The database owns the rules (status catalogue, transitions, delete policy,
 * is_active derivation, trails). This trait gives models typed access to them:
 * casts, query scopes, and the only sanctioned ways to change lifecycle state:
 * transitionTo(), activate() and deactivate(), each with an optional reason
 * that lands in status_change_logs.
 *
 * Refusals from the database are thrown as LifecycleViolation (HTTP 422).
 *
 * @mixin Model
 */
trait HasLifecycle
{
    public static function bootHasLifecycle(): void
    {
        // The database fills and derives status, is_active, is_deleted and the actor
        // columns (defaults and triggers). Reload them after every lifecycle write.
        static::created(fn (Model $model) => $model->refresh());
        static::deleted(fn (Model $model) => $model->exists ? $model->refresh() : null);
        static::registerModelEvent('restored', fn (Model $model) => $model->refresh());
    }

    public function initializeHasLifecycle(): void
    {
        $this->mergeCasts([
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ]);
    }

    /**
     * Rows that are not in the recycle bin (is_deleted = false).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_deleted'), false);
    }

    /**
     * Rows that are not deleted and currently available.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_deleted'), false)
            ->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Rows that are not deleted but switched off (by status or by is_active).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inactive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_deleted'), false)
            ->where($query->qualifyColumn('is_active'), false);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withStatus(Builder $query, string|BackedEnum $status): void
    {
        $query->where($query->qualifyColumn('status'), self::statusCode($status));
    }

    /**
     * @param  Builder<static>  $query
     * @param  iterable<string|BackedEnum>  $statuses
     */
    #[Scope]
    protected function whereStatusIn(Builder $query, iterable $statuses): void
    {
        $codes = [];
        foreach ($statuses as $status) {
            $codes[] = self::statusCode($status);
        }

        $query->whereIn($query->qualifyColumn('status'), $codes);
    }

    /**
     * Move the record to another status. The database checks the catalogue and,
     * where enforced, the allowed transitions; is_active follows the status.
     *
     * @throws LifecycleViolation when the database refuses the change
     */
    public function transitionTo(string|BackedEnum $status, ?string $reason = null): static
    {
        return $this->writeLifecycle(['status' => self::statusCode($status)], $reason);
    }

    /**
     * Make the record available again without changing its status.
     */
    public function activate(?string $reason = null): static
    {
        return $this->writeLifecycle(['is_active' => true], $reason);
    }

    /**
     * Switch the record off without changing its status or deleting it.
     */
    public function deactivate(?string $reason = null): static
    {
        return $this->writeLifecycle(['is_active' => false], $reason);
    }

    /**
     * Save lifecycle attributes with the reason scoped to this write, then reload
     * the values the database derived. On refusal the model keeps its old values.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeLifecycle(array $attributes, ?string $reason): static
    {
        $original = $this->getRawOriginal();

        try {
            LifecycleWrite::withReason(
                $this->getConnection(),
                $reason,
                fn () => $this->forceFill($attributes)->save(),
            );
        } catch (Throwable $exception) {
            $this->setRawAttributes($original, true);

            throw $exception;
        }

        return $this->refresh();
    }

    private static function statusCode(string|BackedEnum $status): string
    {
        return $status instanceof BackedEnum ? (string) $status->value : $status;
    }
}
