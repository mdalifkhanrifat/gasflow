<?php

namespace App\Shared\Lifecycle;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Creates the monthly data_change_logs partitions for this month and the next
 * few, through the owner-run data_change_logs_ensure_partition() (design 08 §9).
 *
 * Partitions must exist before their month starts: rows of a month without a
 * partition land in data_change_logs_default, and PostgreSQL then refuses to
 * create that month's partition. So this runs daily (and after migrations) and
 * keeps several months ready; a failed month is reported and the rest go on.
 */
final class EnsureChangeLogPartitions extends Command
{
    protected $signature = 'audit:change-log-partitions
        {--months=3 : How many months after the current one to prepare}';

    protected $description = 'Create the monthly activity-trail (data_change_logs) partitions ahead of time';

    public function handle(ConnectionInterface $connection): int
    {
        $ahead = $this->option('months');

        if (! is_string($ahead) || ! ctype_digit($ahead) || (int) $ahead > 24) {
            $this->components->error('--months must be a whole number from 0 to 24.');

            return self::FAILURE;
        }

        $failed = false;
        $month = CarbonImmutable::now()->startOfMonth();

        for ($offset = 0; $offset <= (int) $ahead; $offset++) {
            $first = $month->addMonthsNoOverflow($offset);
            $partition = 'data_change_logs_'.$first->format('Y_m');

            if ($connection->scalar('select to_regclass(?)', [$partition]) !== null) {
                $this->components->twoColumnDetail($partition, 'exists');

                continue;
            }

            try {
                // One transaction per month, so a refused month cannot abort the others.
                $connection->transaction(fn () => $connection->select(
                    'select data_change_logs_ensure_partition(?)',
                    [$first->toDateString()],
                ));
                $this->components->twoColumnDetail($partition, '<fg=green>created</>');
            } catch (QueryException $exception) {
                $failed = true;
                $this->components->twoColumnDetail($partition, '<fg=red>failed</>');
                $this->components->error($this->firstLine($exception));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function firstLine(QueryException $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        return trim((string) preg_replace('~^SQLSTATE\[\w+\]: [^:]+: \d+ ERROR:\s*~', '', strtok($message, "\n") ?: $message));
    }
}
