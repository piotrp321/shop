<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

/**
 * Message handled by {@see KillOwnProcessHandler}: makes a participant of ConcurrentCommands die in the middle of its
 * command, so the tests of the harness can check that a participant without an outcome fails the run. Never
 * dispatch it in the PHPUnit process itself.
 */
final readonly class KillOwnProcess
{
}
