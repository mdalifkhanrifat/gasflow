<?php

namespace App\Shared\Lifecycle\RecycleBin;

use App\Shared\Lifecycle\Exceptions\LifecycleViolation;
use App\Shared\Lifecycle\LifecycleWrite;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\RecordNotFoundException;

/**
 * Brings a hidden row back (is_deleted = false). The database clears deleted_at,
 * re-derives is_active from the status and records who restored it and why.
 *
 * The record key is the one the trails use: "12", "bkash", or "3:7" for a
 * composite primary key (lifecycle_record_key()).
 */
final class RestoreHiddenRecord
{
    public function __construct(
        private readonly RecycleBin $recycleBin,
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * @throws RecordNotFoundException when the table is outside the recycle bin or the row is not hidden
     * @throws LifecycleViolation when the database refuses the restore
     */
    public function handle(string $entity, string $recordKey, ?string $reason = null): void
    {
        $where = $this->keyConditions($entity, $recordKey);

        $restored = LifecycleWrite::withReason(
            $this->connection,
            $reason,
            fn (): int => $this->connection->table($entity)
                ->where($where)
                ->where('is_deleted', true)
                ->update(['is_deleted' => false]),
        );

        if ($restored === 0) {
            throw new RecordNotFoundException("No hidden row [{$recordKey}] in [{$entity}].");
        }
    }

    /**
     * @return array<string, string>
     */
    private function keyConditions(string $entity, string $recordKey): array
    {
        $columns = $this->recycleBin->primaryKey($entity);
        // Only composite keys are joined with ":"; a single key value may contain ":".
        $values = count($columns) === 1 ? [$recordKey] : explode(':', $recordKey);

        if (count($values) !== count($columns)) {
            throw new RecordNotFoundException("[{$recordKey}] is not a valid key for [{$entity}].");
        }

        return array_combine($columns, $values);
    }
}
