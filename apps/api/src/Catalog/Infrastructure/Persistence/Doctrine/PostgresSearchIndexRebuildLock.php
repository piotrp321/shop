<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\SearchIndexRebuildLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * A session-level PostgreSQL advisory lock: held until released or until the connection ends, so a process that
 * dies releases it. One key per search alias.
 */
final readonly class PostgresSearchIndexRebuildLock implements SearchIndexRebuildLock
{
    public function __construct(private Connection $connection, private string $alias)
    {
    }

    public function tryAcquire(): bool
    {
        return true === $this->connection->fetchOne('SELECT pg_try_advisory_lock(:key)', ['key' => $this->key()], ['key' => ParameterType::INTEGER]);
    }

    public function release(): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock(:key)', ['key' => $this->key()], ['key' => ParameterType::INTEGER]);
    }

    private function key(): int
    {
        return crc32('catalog.search_index_rebuild:'.$this->alias);
    }
}
