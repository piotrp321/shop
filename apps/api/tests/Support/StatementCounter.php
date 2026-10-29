<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * DBAL middleware (tagged doctrine.middleware in the test environment) that counts SQL statements sent to the
 * database, so tests can assert that an operation issues a constant number of queries.
 */
final class StatementCounter implements Middleware
{
    // Static because DAMA keeps one physical connection across tests, while every test boots a new container
    // (and so a new middleware instance); tests compare counts taken before and after an operation.
    private static int $count = 0;

    public function count(): int
    {
        return self::$count;
    }

    public function wrap(Driver $driver): Driver
    {
        $counter = $this;

        return new class($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                return new class(parent::connect($params), $this->counter) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly StatementCounter $counter)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->counter->increment();

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->counter->increment();

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->counter->increment();

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function increment(): void
    {
        ++self::$count;
    }
}
