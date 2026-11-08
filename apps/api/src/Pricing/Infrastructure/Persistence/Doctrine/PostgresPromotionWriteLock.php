<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Application\Port\PromotionWriteLock;
use Doctrine\DBAL\Connection;

/**
 * A transaction-level PostgreSQL advisory lock on one key of Pricing (pg_advisory_xact_lock, shared or not): held until
 * the transaction of the write ends, also when the process dies. The one statement of the promotion writes outside
 * the records: the data itself goes through them. Promotion writes are rare panel operations, so serialising all of
 * them is cheaper than locking the rows of their variants.
 */
final readonly class PostgresPromotionWriteLock implements PromotionWriteLock
{
    /**
     * The key of the lock, fixed for Pricing (a bigint). PostgresSearchIndexRebuildLock uses the same one-argument form
     * with a crc32 key, below 2^32; this key is above it, so the two never meet.
     */
    public const int KEY = 0x50524F4D4F; // "PROMO"

    public function __construct(private Connection $connection)
    {
    }

    public function exclusive(): void
    {
        $this->lock('pg_advisory_xact_lock');
    }

    public function shared(): void
    {
        $this->lock('pg_advisory_xact_lock_shared');
    }

    private function lock(string $function): void
    {
        // Outside a transaction the lock would end with its own statement and guard nothing.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The lock of the promotion writes needs the transaction of the write.');
        }
        $this->connection->executeStatement('SELECT '.$function.'(CAST(? AS bigint))', [self::KEY]);
    }
}
