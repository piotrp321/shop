<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Timeout;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Sets the time limits of {@see DatabaseTimeouts} on the connections of the application.
 */
#[AsMiddleware]
final readonly class TimeoutMiddleware implements Middleware
{
    public function __construct(private DatabaseTimeouts $timeouts)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TimeoutDriver($driver, $this->timeouts);
    }
}
