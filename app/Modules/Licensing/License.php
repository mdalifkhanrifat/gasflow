<?php

namespace App\Modules\Licensing;

use App\Modules\Licensing\Enums\LicenseEdition;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A signed license payload (design 09 §3), read only after its signature has
 * been verified. Limits are null when unlimited.
 */
final readonly class License
{
    /** The limits a license may set (licenses.max_*). */
    public const array LIMITS = ['organizations', 'buildings', 'flats', 'users'];

    /**
     * @param  list<string>  $domains
     * @param  array<string, int|null>  $limits
     * @param  array<string, bool>  $features
     */
    private function __construct(
        public string $licenseNo,
        public string $licensee,
        public LicenseEdition $edition,
        public array $domains,
        public int $maxActivations,
        public array $limits,
        public array $features,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $validUntil,
        public int $offlineGraceDays,
        public string $keyId,
        public CarbonImmutable $issuedAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidArgumentException naming the first malformed field
     */
    public static function fromPayload(array $payload): self
    {
        $validFrom = self::date($payload, 'valid_from');
        $validUntil = self::date($payload, 'valid_until');

        if ($validUntil->lessThan($validFrom)) {
            throw new InvalidArgumentException('valid_until is before valid_from');
        }

        return new self(
            licenseNo: self::text($payload, 'license_no'),
            licensee: self::text($payload, 'licensee'),
            edition: LicenseEdition::tryFrom(self::text($payload, 'edition')) ?? throw new InvalidArgumentException('edition'),
            domains: self::domains($payload),
            maxActivations: self::integer($payload, 'max_activations', 1, 1000),
            limits: self::limits($payload),
            features: self::features($payload),
            validFrom: $validFrom,
            validUntil: $validUntil,
            offlineGraceDays: self::integer($payload, 'offline_grace_days', 0, 90),
            keyId: self::text($payload, 'key_id'),
            issuedAt: self::timestamp($payload, 'issued_at'),
        );
    }

    public function hasFeature(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    public function limit(string $name): ?int
    {
        return $this->limits[$name] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function text(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($field);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function integer(array $payload, string $field, int $min, int $max): int
    {
        $value = $payload[$field] ?? null;

        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($field);
        }

        return $value;
    }

    /**
     * Calendar date (YYYY-MM-DD), compared in the app timezone.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function date(array $payload, string $field): CarbonImmutable
    {
        $value = self::text($payload, $field);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field);
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function timestamp(array $payload, string $field): CarbonImmutable
    {
        $value = self::text($payload, $field);
        $time = DateTimeImmutable::createFromFormat(DATE_ATOM, str_replace('Z', '+00:00', $value));

        if ($time === false) {
            throw new InvalidArgumentException($field);
        }

        return CarbonImmutable::instance($time);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private static function domains(array $payload): array
    {
        $domains = $payload['domains'] ?? null;

        if (! is_array($domains) || $domains === [] || ! array_is_list($domains)) {
            throw new InvalidArgumentException('domains');
        }

        foreach ($domains as $domain) {
            if (! is_string($domain) || $domain === '') {
                throw new InvalidArgumentException('domains');
            }
        }

        return $domains;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, int|null>
     */
    private static function limits(array $payload): array
    {
        $limits = $payload['limits'] ?? null;

        if (! is_array($limits) || ($limits !== [] && array_is_list($limits))) {
            throw new InvalidArgumentException('limits');
        }

        $result = [];
        foreach (self::LIMITS as $name) {
            $value = $limits[$name] ?? null;

            if ($value !== null && (! is_int($value) || $value < 1)) {
                throw new InvalidArgumentException("limits.{$name}");
            }

            $result[$name] = $value;
        }

        if ($unknown = array_diff(array_keys($limits), self::LIMITS)) {
            throw new InvalidArgumentException('limits.'.implode(', limits.', $unknown));
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, bool>
     */
    private static function features(array $payload): array
    {
        $features = $payload['features'] ?? null;

        if (! is_array($features) || ($features !== [] && array_is_list($features))) {
            throw new InvalidArgumentException('features');
        }

        foreach ($features as $name => $enabled) {
            if (! is_bool($enabled)) {
                throw new InvalidArgumentException("features.{$name}");
            }
        }

        /** @var array<string, bool> $features */
        return $features;
    }
}
