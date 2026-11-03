<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ExceedTimeLimitHandler
{
    public function __invoke(ExceedTimeLimit $message): void
    {
        // "Maximum execution time of 1 second exceeded": a fatal error, which no catch block sees.
        set_time_limit(1);
        // Busy until the limit stops it (the limit counts CPU time).
        while (microtime(true) > 0.0) {
            hash('sha256', 'spin');
        }
    }
}
