<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Timeout;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class TimeoutDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver, private readonly DatabaseTimeouts $timeouts)
    {
        parent::__construct($wrappedDriver);
    }

    public function connect(array $params): Connection
    {
        return new TimeoutConnection(parent::connect($params), $this->timeouts);
    }
}
