<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class KillOwnProcessHandler
{
    public function __invoke(KillOwnProcess $message): void
    {
        posix_kill(posix_getpid(), \SIGKILL);
    }
}
