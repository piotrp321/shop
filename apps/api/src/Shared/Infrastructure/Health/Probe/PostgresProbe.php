<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

use App\Shared\Infrastructure\Health\ProbeResult;
use App\Shared\Infrastructure\Health\ServiceProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

final readonly class PostgresProbe implements ServiceProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'postgres';
    }

    public function probe(): ProbeResult
    {
        $started = hrtime(true);
        try {
            $this->connection->executeQuery('SELECT 1')->free();
        } catch (DbalException) {
            return ProbeResult::down();
        }

        return ProbeResult::upIn((int) round((hrtime(true) - $started) / 1_000_000));
    }
}
