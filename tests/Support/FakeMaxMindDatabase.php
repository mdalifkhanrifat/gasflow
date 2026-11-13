<?php

namespace Tests\Support;

/**
 * Builds a tiny, valid MaxMind DB (.mmdb) file that only holds metadata.
 * Enough for the reader to open it and report its database type, without
 * shipping the real 120 MB DB-IP database in tests.
 *
 * Format: https://maxmind.github.io/MaxMind-DB/ (empty search tree, 16-byte
 * data separator, metadata marker, metadata map).
 */
final class FakeMaxMindDatabase
{
    private const string METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

    public static function bytes(string $databaseType = 'DBIP-City-Lite'): string
    {
        $metadata = self::map([
            'binary_format_major_version' => self::uint(5, 2),
            'binary_format_minor_version' => self::uint(5, 0),
            'build_epoch' => self::uint64(1_700_000_000),
            'database_type' => self::text($databaseType),
            'description' => self::map(['en' => self::text('GasFlow test database')]),
            'ip_version' => self::uint(5, 6),
            'languages' => self::array([self::text('en')]),
            'node_count' => self::uint(6, 0),
            'record_size' => self::uint(5, 24),
        ]);

        return str_repeat("\0", 16).self::METADATA_MARKER.$metadata;
    }

    private static function text(string $value): string
    {
        return chr((2 << 5) | strlen($value)).$value;
    }

    /** uint16 (type 5) or uint32 (type 6), big-endian, shortest form. */
    private static function uint(int $type, int $value): string
    {
        $bytes = ltrim(pack('N', $value), "\0");

        return chr(($type << 5) | strlen($bytes)).$bytes;
    }

    /** uint64 is an extended type (9): control byte, then 9 - 7. */
    private static function uint64(int $value): string
    {
        $bytes = ltrim(pack('J', $value), "\0");

        return chr(strlen($bytes)).chr(9 - 7).$bytes;
    }

    /**
     * @param  array<string, string>  $entries  key => already encoded value
     */
    private static function map(array $entries): string
    {
        $encoded = chr((7 << 5) | count($entries));
        foreach ($entries as $key => $value) {
            $encoded .= self::text($key).$value;
        }

        return $encoded;
    }

    /**
     * Arrays are an extended type (11): control byte, then 11 - 7.
     *
     * @param  list<string>  $items  already encoded values
     */
    private static function array(array $items): string
    {
        return chr(count($items)).chr(11 - 7).implode('', $items);
    }
}
