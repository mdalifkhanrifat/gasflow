<?php

namespace App\Services\Licensing;

/**
 * The exact bytes a license signature covers: object keys sorted at every level,
 * lists kept in order, no whitespace, slashes and Unicode unescaped. The issuer
 * and every installation must produce the same bytes for the same payload.
 */
final class CanonicalJson
{
    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function encode(array $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sort(...), $value);
    }
}
