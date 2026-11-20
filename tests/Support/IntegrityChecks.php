<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Finds the integrity checks in the database: trigger functions that read
 * another table, directly (FROM / JOIN of a table, or of a dynamic %I table)
 * or through a function that does, and write nothing. Under row-level
 * security they must run as gasflow_integrity (decision D52).
 */
final class IntegrityChecks
{
    /**
     * @return list<string> function names
     */
    public static function readingTriggerFunctions(): array
    {
        return DB::table(DB::raw('('.self::SQL.') checks'))->pluck('proname')->all();
    }

    private const string SQL = <<<'SQL'
        WITH RECURSIVE fn AS (
          SELECT p.oid, p.proname, p.prorettype = 'trigger'::regtype AS is_trigger,
                 regexp_replace(p.prosrc, '--[^\n]*', '', 'g') AS src
          FROM pg_proc p
          WHERE p.pronamespace = 'public'::regnamespace
            AND p.prolang IN (SELECT oid FROM pg_language WHERE lanname IN ('plpgsql', 'sql'))
        ), reads AS (
          SELECT f.oid FROM fn f
          WHERE f.src ~* '\m(from|join)\s+%I'
             OR EXISTS (SELECT 1 FROM regexp_matches(f.src, '\m(?:from|join)\s+(?:public\.)?([a-z_][a-z0-9_]*)', 'gi') m(t)
                        JOIN pg_class c ON c.relname = lower(m.t[1])
                         AND c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'p', 'v'))
          UNION
          SELECT f.oid FROM fn f JOIN reads r ON true JOIN fn g ON g.oid = r.oid
          WHERE f.oid <> g.oid AND f.src ~* ('\m' || g.proname || '\s*\(')
        )
        SELECT f.proname
        FROM fn f
        WHERE f.is_trigger AND f.oid IN (SELECT oid FROM reads)
          AND f.src !~* '\m(insert\s+into|delete\s+from)\M|\mupdate\s+[a-z_.%]+\s+set\M'
        ORDER BY 1
        SQL;
}
