<?php

namespace App\Console\Commands\Lifecycle;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backend maintenance override (design 04 "V7.12 Maintenance override contract",
 * 08 §8): a deliberate manual correction of any record, including protected
 * money rows, by an operator.
 *
 * It runs on the separate pgsql_maintenance connection (a gasflow_maintenance
 * member). In one transaction it sets app.maintenance_override / app.lifecycle_reason /
 * app.user_id, runs the statements, checks the deferred rules, previews every
 * changed row from maintenance_override_logs and commits only after a typed
 * confirmation. This is the only code that may set app.maintenance_override.
 */
class MaintenanceOverrideCommand extends Command
{
    public const string CONNECTION = 'pgsql_maintenance';

    public const string CONFIRMATION = 'APPLY';

    public const int MIN_REASON_LENGTH = 10;

    /** Statements may only change rows; transaction control and settings are refused. */
    private const array ALLOWED_STATEMENTS = ['insert', 'update', 'delete', 'with'];

    protected $signature = 'gasflow:maintenance
        {--sql=* : One SQL statement (INSERT, UPDATE, DELETE or WITH); repeat the option for more}
        {--reason= : Why this manual change is made (asked when missing)}
        {--user= : The operator\'s users.id, recorded as the actor}
        {--dry-run : Preview the changes, then roll back}';

    protected $description = 'Correct or hide any record from the backend under the logged maintenance override';

    public function handle(): int
    {
        if (blank(config('database.connections.'.self::CONNECTION.'.username'))) {
            $this->components->error('The '.self::CONNECTION.' connection is not configured on this machine (DB_MAINTENANCE_USERNAME / DB_MAINTENANCE_PASSWORD, operator machines only).');

            return self::FAILURE;
        }

        $connection = DB::connection(self::CONNECTION);
        $role = (string) $connection->scalar('select current_user');

        if (! $connection->scalar("select pg_has_role(current_user, 'gasflow_maintenance', 'MEMBER')")) {
            $this->components->error("The role [{$role}] is not a member of gasflow_maintenance.");

            return self::FAILURE;
        }

        $statements = $this->statements();
        $reason = $this->reason();
        $user = $this->option('user');

        if ($statements === null || $reason === null || ! $this->validUser($user)) {
            return self::FAILURE;
        }

        return $this->apply($connection, $role, $statements, $reason, $user === null ? '' : (string) $user);
    }

    /**
     * @param  list<string>  $statements
     */
    private function apply(Connection $connection, string $role, array $statements, string $reason, string $user): int
    {
        $connection->beginTransaction();

        try {
            $preview = $this->runAndPreview($connection, $statements, $reason, $user);
        } catch (QueryException $exception) {
            $connection->rollBack();
            $this->components->error('The database refused the change, nothing was applied: '.$this->databaseMessage($exception));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        if (! $preview) {
            $connection->rollBack();
            $this->components->warn('Nothing changed, rolled back.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $connection->rollBack();
            $this->components->info('Dry run: rolled back, nothing was applied.');

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $connection->rollBack();
            $this->components->error('A person must confirm the change: run it interactively (or use --dry-run). Rolled back.');

            return self::FAILURE;
        }

        if ($this->ask('Type '.self::CONFIRMATION.' to commit these changes') !== self::CONFIRMATION) {
            $connection->rollBack();
            $this->components->warn('Not confirmed, rolled back.');

            return self::FAILURE;
        }

        try {
            $connection->commit();
        } catch (QueryException $exception) {
            $connection->rollBack();
            $this->components->error('The database refused the commit, nothing was applied: '.$this->databaseMessage($exception));

            return self::FAILURE;
        }

        $this->components->info("Committed as [{$role}]. The changes are recorded in maintenance_override_logs and data_change_logs.");

        return self::SUCCESS;
    }

    /**
     * Run the statements under the override and show what they changed.
     * Returns false when nothing changed.
     *
     * @param  list<string>  $statements
     */
    private function runAndPreview(Connection $connection, array $statements, string $reason, string $user): bool
    {
        $lastLogId = (int) $connection->scalar('select coalesce(max(id), 0) from maintenance_override_logs');

        $connection->select(
            "select set_config('app.maintenance_override', 'on', true),
                    set_config('app.lifecycle_reason', ?, true),
                    set_config('app.user_id', ?, true),
                    set_config('app.client_type', 'console', true)",
            [$reason, $user],
        );

        $reported = 0;
        foreach ($statements as $number => $statement) {
            $rows = $connection->affectingStatement($statement);
            $reported += $rows;
            $this->components->twoColumnDetail('Statement '.($number + 1), "{$rows} rows reported");
        }

        // Balance and journal checks normally run at COMMIT; run them now so a
        // refusal shows up before the confirmation.
        $connection->statement('set constraints all immediate');

        /** @var list<object{entity_name: string, record_key: string, operation: string, old_row: ?string, new_row: ?string}> $changes */
        $changes = $connection->select(
            'select entity_name, record_key, operation, old_row, new_row
               from maintenance_override_logs
              where id > ? and changed_at = now() and db_role = current_user
              order by id',
            [$lastLogId],
        );

        if ($changes === [] && $reported === 0) {
            return false;
        }

        $this->newLine();

        if ($changes === []) {
            $this->line('  No table under the lifecycle standard changed, so there is no row-by-row preview.');
        } else {
            $this->table(
                ['Table', 'Record', 'Operation', 'Changed columns'],
                array_map(fn (object $change): array => [
                    $change->entity_name,
                    $change->record_key,
                    $change->operation,
                    $this->changedColumns($change->old_row, $change->new_row),
                ], $changes),
            );
        }
        $this->line('  Reason: '.$reason);
        $this->line('  Only tables under the lifecycle standard are listed. A DELETE that becomes a hide reports 0 rows.');
        $this->newLine();

        return true;
    }

    private function changedColumns(?string $oldRow, ?string $newRow): string
    {
        if ($oldRow === null) {
            return '(new row)';
        }

        /** @var array<string, mixed> $old */
        $old = json_decode($oldRow, true);
        /** @var array<string, mixed> $new */
        $new = json_decode((string) $newRow, true);

        $changed = array_keys(array_filter(
            $new,
            fn (mixed $value, string $column): bool => ! array_key_exists($column, $old) || $old[$column] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        return $changed === [] ? '(none)' : implode(', ', $changed);
    }

    /**
     * @return list<string>|null
     */
    private function statements(): ?array
    {
        $statements = array_values(array_filter(
            array_map(fn (mixed $statement): string => trim((string) $statement), (array) $this->option('sql')),
            fn (string $statement): bool => $statement !== '',
        ));

        if ($statements === []) {
            $this->components->error('Give at least one statement with --sql.');

            return null;
        }

        foreach ($statements as $statement) {
            if (! in_array($this->firstKeyword($statement), self::ALLOWED_STATEMENTS, true)) {
                $this->components->error('Only INSERT, UPDATE, DELETE or WITH statements are allowed: '.$statement);

                return null;
            }
        }

        return $statements;
    }

    private function firstKeyword(string $statement): string
    {
        // Skip leading -- and /* */ comments, so they cannot hide the real command.
        $code = (string) preg_replace('~\A(?:\s+|--[^\n]*(?:\n|\z)|/\*.*?\*/)*~s', '', $statement);

        return preg_match('~\A([a-z]+)~i', $code, $match) === 1 ? strtolower($match[1]) : '';
    }

    private function reason(): ?string
    {
        $reason = trim((string) ($this->option('reason') ?? $this->ask('Why is this change made? (recorded with every changed row)')));

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            $this->components->error('The reason must explain the change in at least '.self::MIN_REASON_LENGTH.' characters.');

            return null;
        }

        return $reason;
    }

    private function validUser(mixed $user): bool
    {
        if ($user === null || (is_string($user) && ctype_digit($user) && (int) $user > 0)) {
            return true;
        }

        $this->components->error('--user must be a users.id (a positive whole number).');

        return false;
    }

    private function databaseMessage(QueryException $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        return trim((string) preg_replace('~^SQLSTATE\[\w+\]: [^:]+: \d+ ERROR:\s*~', '', strtok($message, "\n") ?: $message));
    }
}
