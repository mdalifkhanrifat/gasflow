<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Replaces secret values in a log record's context and extra data with
 * "[redacted]" before any handler writes them (design 04: log redaction is
 * mandatory). Matching is by key, at any depth, ignoring case, "-" and "_":
 * long names match anywhere in the key (access_token, X-Api-Key,
 * password_confirmation), short ones only exactly (pin, but not shipping).
 *
 * Messages are not scanned: never interpolate a secret into a message.
 */
final class SensitiveDataProcessor implements ProcessorInterface
{
    public const string MASK = '[redacted]';

    /** Matched anywhere in the normalised key. */
    private const array CONTAINS = [
        'password', 'passphrase', 'secret', 'token', 'apikey', 'authorization',
        'cookie', 'privatekey', 'credential', 'cardnumber', 'signature',
    ];

    /** Matched only as the whole normalised key. */
    private const array EXACT = ['pin', 'otp', 'cvv', 'cvc', 'pan', 'nid'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $normalised = str_replace(['-', '_', ' '], '', strtolower($key));

        if (in_array($normalised, self::EXACT, true)) {
            return true;
        }

        foreach (self::CONTAINS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
