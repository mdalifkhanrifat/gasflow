<?php

namespace App\Services\Lifecycle\RecycleBin;

use Carbon\CarbonImmutable;

/**
 * One row in the recycle bin: the record (secrets redacted) and who hid it,
 * when, why, and from which device and place.
 */
final readonly class HiddenRecord
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public string $entity,
        public string $recordKey,
        public array $record,
        public CarbonImmutable $deletedAt,
        public ?int $deletedBy,
        public ?string $deletedByName,
        public ?string $reason,
        public ?string $clientType,
        public ?string $deviceKind,
        public ?string $ipCity,
        public ?string $ipCountry,
        public ?float $geoLat,
        public ?float $geoLon,
    ) {}
}
