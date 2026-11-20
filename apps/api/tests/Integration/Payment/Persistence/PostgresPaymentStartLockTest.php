<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment\Persistence;

use App\Payment\Domain\OrderId;
use App\Payment\Infrastructure\Persistence\Doctrine\PostgresPaymentStartLock;
use App\Tests\Integration\Pricing\Promotion\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The lock of the payment starts of one order on PostgreSQL: a transaction-level advisory lock taken without waiting,
 * seen from another connection. Both connections are the test's own (TestDatabase), so each transaction is real.
 */
final class PostgresPaymentStartLockTest extends TestCase
{
    private const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000c1';
    private const string OTHER_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000c2';

    private Connection $connection;
    private Connection $other;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
        $this->other = TestDatabase::connect();
        $this->connection->beginTransaction();
        $this->other->beginTransaction();
    }

    protected function tearDown(): void
    {
        foreach ([$this->connection, $this->other] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
        parent::tearDown();
    }

    public function testTheLockOfAnOrderIsRefusedToAnotherTransactionAtOnce(): void
    {
        self::assertTrue(new PostgresPaymentStartLock($this->connection)->tryAcquire(OrderId::fromString(self::ORDER)));

        $started = microtime(true);
        self::assertFalse(new PostgresPaymentStartLock($this->other)->tryAcquire(OrderId::fromString(self::ORDER)));
        self::assertLessThan(0.5, microtime(true) - $started, 'refused without waiting');
    }

    public function testTheLockOfAnotherOrderIsFree(): void
    {
        new PostgresPaymentStartLock($this->connection)->tryAcquire(OrderId::fromString(self::ORDER));

        self::assertTrue(new PostgresPaymentStartLock($this->other)->tryAcquire(OrderId::fromString(self::OTHER_ORDER)));
    }

    /**
     * Within one transaction the lock is taken again (the functional tests start payments in one transaction).
     */
    public function testTheTransactionThatHoldsTheLockTakesItAgain(): void
    {
        $lock = new PostgresPaymentStartLock($this->connection);
        $lock->tryAcquire(OrderId::fromString(self::ORDER));

        self::assertTrue($lock->tryAcquire(OrderId::fromString(self::ORDER)));
    }

    public function testTheLockGoesWithTheTransaction(): void
    {
        new PostgresPaymentStartLock($this->connection)->tryAcquire(OrderId::fromString(self::ORDER));
        $this->connection->commit();

        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
        self::assertTrue(new PostgresPaymentStartLock($this->other)->tryAcquire(OrderId::fromString(self::ORDER)));
    }

    public function testTheLockIsTheTwoKeyAdvisoryLockOfPaymentOnAHashOfTheOrder(): void
    {
        new PostgresPaymentStartLock($this->connection)->tryAcquire(OrderId::fromString(self::ORDER));

        $hash = crc32(self::ORDER);
        $key = $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND classid = CAST(? AS int)::oid AND objid = CAST(CAST(? AS int) AS oid) AND objsubid = 2",
            [PostgresPaymentStartLock::LOCK_NAMESPACE, $key],
        ));
    }

    public function testTheLockIsRefusedOutsideATransaction(): void
    {
        $this->other->rollBack();

        $this->expectException(\LogicException::class);

        new PostgresPaymentStartLock($this->other)->tryAcquire(OrderId::fromString(self::ORDER));
    }
}
