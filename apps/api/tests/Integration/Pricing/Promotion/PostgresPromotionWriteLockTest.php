<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Promotion;

use App\Pricing\Infrastructure\Persistence\Doctrine\PostgresPromotionWriteLock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The lock of the promotion writes on PostgreSQL: a transaction-level advisory lock, seen from another connection.
 * Both connections are the test's own (TestDatabase), so each transaction is real.
 */
final class PostgresPromotionWriteLockTest extends TestCase
{
    private Connection $connection;
    private Connection $other;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
        $this->other = TestDatabase::connect();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->close();
        $this->other->close();
        parent::tearDown();
    }

    public function testTheExclusiveLockKeepsOutEveryOtherWriteUntilTheTransactionEnds(): void
    {
        new PostgresPromotionWriteLock($this->connection)->exclusive();

        self::assertFalse($this->otherTakes('pg_try_advisory_xact_lock_shared'));
        self::assertFalse($this->otherTakes('pg_try_advisory_xact_lock'));
    }

    public function testTheSharedLockKeepsOutOnlyTheExclusiveOne(): void
    {
        new PostgresPromotionWriteLock($this->connection)->shared();

        self::assertTrue($this->otherTakes('pg_try_advisory_xact_lock_shared'));
        self::assertFalse($this->otherTakes('pg_try_advisory_xact_lock'));
    }

    public function testTheLockGoesWithTheTransaction(): void
    {
        $lock = new PostgresPromotionWriteLock($this->other);
        $this->other->beginTransaction();
        $lock->exclusive();
        $this->other->commit();

        self::assertSame(0, $this->other->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
    }

    public function testTheLockIsRefusedOutsideATransaction(): void
    {
        $this->expectException(\LogicException::class);

        new PostgresPromotionWriteLock($this->other)->exclusive();
    }

    private function otherTakes(string $function): bool
    {
        $this->other->beginTransaction();
        try {
            return true === $this->other->fetchOne('SELECT '.$function.'(CAST(? AS bigint))', [PostgresPromotionWriteLock::KEY]);
        } finally {
            $this->other->rollBack();
        }
    }
}
