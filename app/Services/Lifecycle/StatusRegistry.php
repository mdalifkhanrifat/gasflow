<?php

namespace App\Services\Lifecycle;

use BackedEnum;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Read model of the status catalogue (status_definitions, status_transitions,
 * lifecycle_entities.enforce_transitions), cached with version-key invalidation.
 *
 * The database stays the authority and enforces every rule. The registry lets
 * the app ask in advance (labels, tones, allowed transitions) and gives the UI
 * one source for status badges: toArray() becomes the Inertia "statuses" prop.
 *
 * Tables without their own statuses use the generic "*" set, exactly like
 * lifecycle_status_set() in the database.
 */
final class StatusRegistry
{
    public const string VERSION_KEY = 'lifecycle:status-registry:version';

    private const string DATA_KEY = 'lifecycle:status-registry:data:';

    private const string GENERIC_SET = '*';

    /**
     * @var array{
     *     statuses: array<string, array<string, StatusDefinition>>,
     *     transitions: array<string, array<string, array<string, array{requires_reason: bool, permission_code: ?string}>>>,
     *     enforced: array<string, true>,
     * }|null
     */
    private ?array $catalogue = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly ConnectionInterface $database,
    ) {}

    /**
     * Statuses of an entity (its own set, or the generic one), in display order.
     *
     * @return array<string, StatusDefinition>
     */
    public function statusesFor(string $entity): array
    {
        return $this->catalogue()['statuses'][$this->statusSetFor($entity)] ?? [];
    }

    public function find(string $entity, string|BackedEnum $code): ?StatusDefinition
    {
        return $this->statusesFor($entity)[self::code($code)] ?? null;
    }

    /**
     * The label in the given (default: current) locale; the code itself when unknown.
     */
    public function label(string $entity, string|BackedEnum $code, ?string $locale = null): string
    {
        return $this->find($entity, $code)?->labelFor($locale ?? app()->getLocale()) ?? self::code($code);
    }

    /**
     * The badge tone (neutral, info, success, warning, danger, muted).
     */
    public function tone(string $entity, string|BackedEnum $code): string
    {
        return $this->find($entity, $code)->tone ?? 'neutral';
    }

    /**
     * Whether the database would accept moving a record from one status to another.
     */
    public function canTransition(string $entity, string|BackedEnum $from, string|BackedEnum $to): bool
    {
        $from = self::code($from);
        $to = self::code($to);

        if ($from === $to || $this->find($entity, $to) === null) {
            return false;
        }

        if (! isset($this->catalogue()['enforced'][$entity])) {
            return true;
        }

        return isset($this->catalogue()['transitions'][$this->statusSetFor($entity)][$from][$to]);
    }

    /**
     * Whether a configured transition asks the user for a reason.
     */
    public function requiresReason(string $entity, string|BackedEnum $from, string|BackedEnum $to): bool
    {
        $rule = $this->catalogue()['transitions'][$this->statusSetFor($entity)][self::code($from)][self::code($to)] ?? null;

        return $rule['requires_reason'] ?? false;
    }

    /**
     * Every status as { entity: { code: { label, tone } } } for the UI.
     *
     * @return array<string, array<string, array{label: string, tone: string}>>
     */
    public function toArray(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $shared = [];

        foreach ($this->catalogue()['statuses'] as $entity => $statuses) {
            foreach ($statuses as $code => $status) {
                $shared[$entity][$code] = ['label' => $status->labelFor($locale), 'tone' => $status->tone];
            }
        }

        return $shared;
    }

    /**
     * Make every process reload the catalogue (after statuses or transitions change).
     */
    public function flush(): void
    {
        $this->cache->forever(self::VERSION_KEY, $this->version() + 1);
        $this->catalogue = null;
    }

    private function statusSetFor(string $entity): string
    {
        return isset($this->catalogue()['statuses'][$entity]) ? $entity : self::GENERIC_SET;
    }

    /**
     * @return array{
     *     statuses: array<string, array<string, StatusDefinition>>,
     *     transitions: array<string, array<string, array<string, array{requires_reason: bool, permission_code: ?string}>>>,
     *     enforced: array<string, true>,
     * }
     */
    private function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        /** @var array{statuses: list<array<string, mixed>>, transitions: list<array<string, mixed>>, enforced: list<string>} $rows */
        $rows = $this->cache->remember(
            self::DATA_KEY.$this->version(),
            now()->addDay(),
            fn (): array => $this->loadRows(),
        );

        $statuses = [];
        foreach ($rows['statuses'] as $row) {
            /** @var array{entity_name: string, code: string, label: string, tone: string, implies_active: ?bool, is_initial: bool, is_terminal: bool, sort_order: int, translations: string} $row */
            $statuses[$row['entity_name']][$row['code']] = StatusDefinition::fromRow($row);
        }

        $transitions = [];
        foreach ($rows['transitions'] as $row) {
            /** @var array{entity_name: string, from_code: string, to_code: string, requires_reason: bool, permission_code: ?string} $row */
            $transitions[$row['entity_name']][$row['from_code']][$row['to_code']] = [
                'requires_reason' => $row['requires_reason'],
                'permission_code' => $row['permission_code'],
            ];
        }

        return $this->catalogue = [
            'statuses' => $statuses,
            'transitions' => $transitions,
            'enforced' => array_fill_keys($rows['enforced'], true),
        ];
    }

    /**
     * Plain arrays only, so the cache never holds PHP objects across deploys.
     *
     * @return array{statuses: list<array<string, mixed>>, transitions: list<array<string, mixed>>, enforced: list<string>}
     */
    private function loadRows(): array
    {
        $statuses = $this->database->select(
            'select entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations::text as translations
               from status_definitions
              where is_active
              order by entity_name, sort_order, code',
        );
        $transitions = $this->database->select(
            'select entity_name, from_code, to_code, requires_reason, permission_code
               from status_transitions
              where is_active',
        );
        $enforced = $this->database->select(
            'select entity_name from lifecycle_entities where enforce_transitions',
        );

        return [
            'statuses' => self::toRows($statuses),
            'transitions' => self::toRows($transitions),
            'enforced' => array_map(
                fn (array $row): string => (string) $row['entity_name'],
                self::toRows($enforced),
            ),
        ];
    }

    /**
     * @param  array<int, mixed>  $records
     * @return list<array<string, mixed>>
     */
    private static function toRows(array $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            /** @var array<string, mixed> $row */
            $row = (array) $record;
            $rows[] = $row;
        }

        return $rows;
    }

    private function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 1);
    }

    private static function code(string|BackedEnum $code): string
    {
        return $code instanceof BackedEnum ? (string) $code->value : $code;
    }
}
