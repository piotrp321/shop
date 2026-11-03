<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

/**
 * Message handled by {@see ExceedTimeLimitHandler}: makes a participant of ConcurrentCommands end with a fatal error
 * (not a Throwable), so the tests of the harness can check that such a participant never closes the parent's
 * connections. Never dispatch it in the PHPUnit process itself.
 */
final readonly class ExceedTimeLimit
{
}
