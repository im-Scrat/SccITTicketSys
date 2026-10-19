<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Logging channel `tap` that installs {@see RedactSecretsProcessor}. Listed on
 * every file and stream channel in config/logging.php; a stack channel inherits
 * the processors of the channels it combines.
 */
final class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new RedactSecretsProcessor);
        }
    }
}
