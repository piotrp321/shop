<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backfill;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The completed steps of app:backfill (app_backfill), read and written with plain statements.
 */
final readonly class BackfillLog
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, string> step name => when it completed last (as stored)
     */
    public function completed(): array
    {
        /** @var array<string, string> $completed */
        $completed = $this->connection->fetchAllKeyValue('SELECT name, completed_at FROM app_backfill');

        return $completed;
    }

    /**
     * Records the step as completed now, also when it completed before (a forced run).
     */
    public function record(string $name): void
    {
        $this->connection->executeStatement(
            'INSERT INTO app_backfill (name, completed_at) VALUES (:name, :now) ON CONFLICT (name) DO UPDATE SET completed_at = excluded.completed_at',
            ['name' => $name, 'now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
