<?php

namespace App\Shared\Lifecycle\RecycleBin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\RecordNotFoundException;

/**
 * Generic recycle bin over every table the app may hide and restore
 * (lifecycle_entities: soft_delete_policy = allowed, hard_delete_policy = forbidden).
 *
 * Who / when / why / device / place come from the latest "hide" entry in
 * data_change_logs. Row-level security keeps every tenant to its own rows.
 * Columns listed in lifecycle_entities.redacted_columns are never shown.
 */
final class RecycleBin
{
    public const string REDACTED = '[redacted]';

    /** @var array<string, array{module: string, record_class: string, redacted_columns: list<string>}>|null */
    private ?array $entities = null;

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Tables that can hold hidden rows, by module.
     *
     * @return array<string, array{module: string, record_class: string, redacted_columns: list<string>}>
     */
    public function entities(): array
    {
        if ($this->entities !== null) {
            return $this->entities;
        }

        $rows = $this->connection->select(
            "select entity_name, module, record_class, array_to_json(redacted_columns)::text as redacted_columns
               from lifecycle_entities
              where entity_name <> '*' and soft_delete_policy = 'allowed' and hard_delete_policy = 'forbidden'
              order by module, entity_name",
        );

        $entities = [];
        foreach ($rows as $row) {
            /** @var list<string> $redacted */
            $redacted = json_decode((string) $row->redacted_columns, true) ?: [];
            $entities[(string) $row->entity_name] = [
                'module' => (string) $row->module,
                'record_class' => (string) $row->record_class,
                'redacted_columns' => $redacted,
            ];
        }

        return $this->entities = $entities;
    }

    public function contains(string $entity): bool
    {
        return isset($this->entities()[$entity]);
    }

    /**
     * @throws RecordNotFoundException for a table outside the recycle bin
     */
    public function ensureContains(string $entity): void
    {
        if (! $this->contains($entity)) {
            throw new RecordNotFoundException("[{$entity}] is not part of the recycle bin.");
        }
    }

    /**
     * Number of hidden rows per table (one query).
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $entities = array_keys($this->entities());
        if ($entities === []) {
            return [];
        }

        $grammar = $this->connection->table('lifecycle_entities')->getGrammar();
        $parts = array_map(
            fn (string $entity): string => 'select ? as entity_name, count(*) as hidden from '.$grammar->wrapTable($entity).' where is_deleted',
            $entities,
        );

        $counts = [];
        foreach ($this->connection->select(implode(' union all ', $parts), $entities) as $row) {
            $counts[(string) $row->entity_name] = (int) $row->hidden;
        }

        return $counts;
    }

    /**
     * Hidden rows of one table, most recently hidden first.
     *
     * @return LengthAwarePaginator<int, HiddenRecord>
     *
     * @throws RecordNotFoundException for a table outside the recycle bin
     */
    public function hidden(string $entity, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $this->ensureContains($entity);
        $recordKey = 'lifecycle_record_key(?::regclass, to_jsonb(t))';

        $query = $this->connection->table("{$entity} as t")
            ->selectRaw("to_jsonb(t)::text as record, {$recordKey} as record_key, t.deleted_at, t.updated_by as deleted_by", [$entity])
            ->addSelect(['u.name as deleted_by_name', 'h.reason', 'h.client_type', 'h.device_kind', 'h.ip_city', 'h.ip_country', 'h.geo_lat', 'h.geo_lon'])
            ->leftJoin('users as u', 'u.id', '=', 't.updated_by')
            ->leftJoinLateral(
                fn (Builder $latest) => $latest->from('data_change_logs as d')
                    ->select(['d.reason', 'd.client_type', 'd.device_kind', 'd.ip_city', 'd.ip_country', 'd.geo_lat', 'd.geo_lon'])
                    ->where('d.entity_name', $entity)
                    ->where('d.operation', 'hide')
                    ->whereRaw("d.record_key = {$recordKey}", [$entity])
                    ->orderByDesc('d.changed_at')
                    ->limit(1),
                'h',
            )
            ->where('t.is_deleted', true)
            ->orderByDesc('t.deleted_at');

        // Rows hidden in one transaction share deleted_at; the key keeps pages stable.
        foreach ($this->primaryKey($entity) as $column) {
            $query->orderByDesc("t.{$column}");
        }

        $redacted = $this->entities()[$entity]['redacted_columns'];

        return $query->paginate($perPage, page: $page)
            ->through(fn (mixed $row): HiddenRecord => $this->toHiddenRecord($entity, (array) $row, $redacted));
    }

    /**
     * Primary-key columns of a table, in key order.
     *
     * @return list<string>
     */
    public function primaryKey(string $entity): array
    {
        $this->ensureContains($entity);

        $rows = $this->connection->select(
            'select a.attname
               from pg_index i
               join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey)
              where i.indrelid = ?::regclass and i.indisprimary
              order by array_position(i.indkey::int2[], a.attnum)',
            [$entity],
        );

        return array_map(fn (mixed $row): string => (string) ((array) $row)['attname'], array_values($rows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $redacted
     */
    private function toHiddenRecord(string $entity, array $row, array $redacted): HiddenRecord
    {
        /** @var array<string, mixed> $record */
        $record = json_decode(self::text($row, 'record') ?? '{}', true);
        foreach ($redacted as $column) {
            if (array_key_exists($column, $record)) {
                $record[$column] = self::REDACTED;
            }
        }

        $deletedBy = self::text($row, 'deleted_by');
        $geoLat = self::text($row, 'geo_lat');
        $geoLon = self::text($row, 'geo_lon');

        return new HiddenRecord(
            entity: $entity,
            recordKey: (string) self::text($row, 'record_key'),
            record: $record,
            deletedAt: CarbonImmutable::parse((string) self::text($row, 'deleted_at')),
            deletedBy: $deletedBy === null ? null : (int) $deletedBy,
            deletedByName: self::text($row, 'deleted_by_name'),
            reason: self::text($row, 'reason'),
            clientType: self::text($row, 'client_type'),
            deviceKind: self::text($row, 'device_kind'),
            ipCity: self::text($row, 'ip_city'),
            ipCountry: self::text($row, 'ip_country'),
            geoLat: $geoLat === null ? null : (float) $geoLat,
            geoLon: $geoLon === null ? null : (float) $geoLon,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
