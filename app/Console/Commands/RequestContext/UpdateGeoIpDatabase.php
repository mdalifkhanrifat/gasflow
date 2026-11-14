<?php

namespace App\Console\Commands\RequestContext;

use Carbon\CarbonImmutable;
use GeoIp2\Database\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Downloads the monthly DB-IP City Lite database (this month, or last month if
 * this month's file is not published yet), checks it is a real City database,
 * and only then replaces the current file. A failed update keeps the old file.
 */
final class UpdateGeoIpDatabase extends Command
{
    protected $signature = 'audit:geoip-update';

    protected $description = 'Download the DB-IP City Lite database used for IP → city lookups';

    public function handle(): int
    {
        $target = (string) config('audit.geoip.database');
        $urlPattern = (string) config('audit.geoip.download_url');
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $compressed = "{$target}.download.gz";
        $candidate = "{$target}.new";

        try {
            $url = $this->download($urlPattern, $compressed);
            $this->gunzip($compressed, $candidate);
            $type = (new Reader($candidate))->metadata()->databaseType;

            if (! str_contains($type, 'City')) {
                throw new RuntimeException("Expected a City database, got [{$type}].");
            }

            rename($candidate, $target);
        } catch (Throwable $exception) {
            $this->error('GeoIP database not updated: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            foreach ([$compressed, $candidate] as $leftover) {
                if (is_file($leftover)) {
                    unlink($leftover);
                }
            }
        }

        $this->info("GeoIP database updated from {$url}.");

        return self::SUCCESS;
    }

    /**
     * @return string the URL that was downloaded
     */
    private function download(string $urlPattern, string $destination): string
    {
        $now = CarbonImmutable::now();

        foreach ([$now, $now->subMonthNoOverflow()] as $month) {
            $url = sprintf($urlPattern, $month->format('Y-m'));
            $response = Http::timeout(600)->sink($destination)->get($url);

            if ($response->successful()) {
                return $url;
            }
        }

        throw new RuntimeException('No database published for this month or the previous one.');
    }

    private function gunzip(string $source, string $destination): void
    {
        $input = gzopen($source, 'rb');
        $output = fopen($destination, 'wb');

        if ($input === false || $output === false) {
            throw new RuntimeException('Cannot open the downloaded file.');
        }

        try {
            while (! gzeof($input)) {
                $chunk = gzread($input, 1 << 20);
                if ($chunk === false) {
                    throw new RuntimeException('The download is not a valid gzip file.');
                }
                fwrite($output, $chunk);
            }
        } finally {
            gzclose($input);
            fclose($output);
        }
    }
}
