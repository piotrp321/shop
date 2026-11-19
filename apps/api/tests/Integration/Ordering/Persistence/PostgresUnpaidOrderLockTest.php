<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\UnpaidOrderIdentity;
use App\Ordering\Infrastructure\Persistence\Doctrine\PostgresUnpaidOrderLock;
use App\Tests\Integration\Pricing\Promotion\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The locks of the limit of unpaid orders on PostgreSQL (ABUSE-LIMITS, D8): transaction-level advisory locks on the
 * address and on the identity, seen from another connection. Both connections are the test's own (TestDatabase), so
 * each transaction is real.
 */
final class PostgresUnpaidOrderLockTest extends TestCase
{
    private const string ADDRESS = '203.0.113.7';

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

    public function testTheAddressAndTheIdentityAreLockedUntilTheEndOfTheTransaction(): void
    {
        new PostgresUnpaidOrderLock($this->connection)->lock(self::ADDRESS, self::guest('jan@example.com'));

        self::assertFalse($this->otherTakes(PostgresUnpaidOrderLock::ADDRESS_NAMESPACE, self::ADDRESS), 'the address is locked');
        self::assertFalse($this->otherTakes(PostgresUnpaidOrderLock::IDENTITY_NAMESPACE, 'email:jan@example.com'), 'the identity is locked');
        self::assertTrue($this->otherTakes(PostgresUnpaidOrderLock::ADDRESS_NAMESPACE, '198.51.100.1'), 'another address is free');
        self::assertTrue($this->otherTakes(PostgresUnpaidOrderLock::IDENTITY_NAMESPACE, 'email:ewa@example.com'), 'another identity is free');

        $this->connection->commit();

        self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
        self::assertTrue($this->otherTakes(PostgresUnpaidOrderLock::ADDRESS_NAMESPACE, self::ADDRESS));
        self::assertTrue($this->otherTakes(PostgresUnpaidOrderLock::IDENTITY_NAMESPACE, 'email:jan@example.com'));
    }

    public function testAnotherTransactionWaitsForTheLockedAddress(): void
    {
        new PostgresUnpaidOrderLock($this->connection)->lock(self::ADDRESS, self::guest('jan@example.com'));
        $this->other->executeStatement("SET LOCAL lock_timeout = '200ms'");

        try {
            new PostgresUnpaidOrderLock($this->other)->lock(self::ADDRESS, self::guest('ewa@example.com'));
            self::fail('Expected the lock of the address to wait.');
        } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTheLocksAreTwoKeyAdvisoryLocksOfOrderingOnHashesOfTheKeys(): void
    {
        new PostgresUnpaidOrderLock($this->connection)->lock(self::ADDRESS, UnpaidOrderIdentity::of(OrderOwner::customer('kc-sub-1'), EmailAddress::fromString('jan@example.com')));

        self::assertSame([
            [PostgresUnpaidOrderLock::ADDRESS_NAMESPACE, self::hash(self::ADDRESS)],
            [PostgresUnpaidOrderLock::IDENTITY_NAMESPACE, self::hash('customer:kc-sub-1')],
        ], array_map(
            static fn (array $row): array => [(int) $row[0], (int) $row[1]],
            $this->connection->fetchAllNumeric("SELECT classid::bigint, CAST(objid::bigint AS bigint) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND objsubid = 2 ORDER BY classid"),
        ));
    }

    public function testTheNamespacesAreTheirOwn(): void
    {
        $taken = [0x5041, 0x5354, 0x1A7E, 0x1A7F]; // Payment, Inventory, the barriers of the concurrency tests

        self::assertNotContains(PostgresUnpaidOrderLock::ADDRESS_NAMESPACE, $taken);
        self::assertNotContains(PostgresUnpaidOrderLock::IDENTITY_NAMESPACE, $taken);
        self::assertNotSame(PostgresUnpaidOrderLock::ADDRESS_NAMESPACE, PostgresUnpaidOrderLock::IDENTITY_NAMESPACE);
    }

    public function testTheLockIsRefusedOutsideATransaction(): void
    {
        $this->other->rollBack();

        $this->expectException(\LogicException::class);

        new PostgresUnpaidOrderLock($this->other)->lock(self::ADDRESS, self::guest('jan@example.com'));
    }

    private function otherTakes(int $namespace, string $key): bool
    {
        return true === $this->other->fetchOne('SELECT pg_try_advisory_xact_lock(CAST(? AS int), CAST(? AS int))', [$namespace, self::signed(crc32($key))]);
    }

    /** The second key as pg_locks shows it: objid is the unsigned 32 bits. */
    private static function hash(string $key): int
    {
        return crc32($key);
    }

    private static function signed(int $hash): int
    {
        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }

    private static function guest(string $email): UnpaidOrderIdentity
    {
        return UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString($email));
    }
}
