<?php

namespace App\Shared\Lifecycle;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * A change refused by the database lifecycle rules: unknown status, illegal
 * transition, protected record, refused maintenance override, ...
 *
 * The lifecycle triggers raise these with SQLSTATE P0001 (raise_exception).
 * As a validation error it becomes a 422 JSON response for the API and an
 * error in the "lifecycle" field for Inertia forms.
 */
final class LifecycleViolation extends ValidationException
{
    /** SQLSTATE used by PL/pgSQL RAISE EXCEPTION. */
    public const string SQLSTATE = 'P0001';

    public const string FIELD = 'lifecycle';

    /**
     * The violation for a lifecycle refusal, or null for any other database error.
     */
    public static function fromQueryException(QueryException $exception): ?self
    {
        if ((string) $exception->getCode() !== self::SQLSTATE) {
            return null;
        }

        $violation = self::withMessages([self::FIELD => self::readableMessage($exception)]);
        $violation->status(422);

        return $violation;
    }

    /**
     * The first line of the database message, without the "ERROR:" prefix
     * and the PL/pgSQL context lines.
     */
    private static function readableMessage(QueryException $exception): string
    {
        $raw = (string) ($exception->errorInfo[2] ?? $exception->getPrevious()?->getMessage() ?? '');
        $firstLine = strtok($raw, "\n");

        return trim((string) preg_replace('/^(SQLSTATE\[\w+\]:.*?\d+\s+)?ERROR:\s*/', '', $firstLine === false ? $raw : $firstLine));
    }
}
