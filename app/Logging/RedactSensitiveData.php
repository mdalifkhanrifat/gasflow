<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Log channel "tap" (config/logging.php): adds the SensitiveDataProcessor to
 * a channel, so nothing secret reaches a file, stderr or a log service.
 */
final class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new SensitiveDataProcessor);
        }
    }
}
