<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\CategoryTreeLock;
use Doctrine\DBAL\Connection;

/**
 * A transaction-level PostgreSQL advisory lock on one key of the category tree (pg_advisory_xact_lock): held until the
 * transaction of the change ends, also when the process dies. Like the lock of the promotion writes, the one statement
 * of the tree writes outside the records besides the paths of a moved subtree.
 */
final readonly class PostgresCategoryTreeLock implements CategoryTreeLock
{
    /**
     * The key of the lock, fixed for the tree (a bigint). Above 2^32 like the one of the promotions
     * (PostgresPromotionWriteLock::KEY, "PROMO"), so it never meets the crc32 keys of PostgresSearchIndexRebuildLock.
     */
    public const int KEY = 0x4341544547; // "CATEG"

    public function __construct(private Connection $connection)
    {
    }

    public function exclusive(): void
    {
        // Outside a transaction the lock would end with its own statement and guard nothing.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The lock of the category tree needs the transaction of the change.');
        }
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [self::KEY]);
    }
}
