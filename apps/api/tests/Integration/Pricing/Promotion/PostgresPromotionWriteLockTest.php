<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Promotion;

use App\Pricing\Infrastructure\Persistence\Doctrine\PostgresPromotionWriteLock;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * The lock of the promotion writes on PostgreSQL: a transaction-level advisory lock, seen from another connection.
 * Both connections are the test's own (the test database of config/packages/doctrine.yaml, without the kernel), so
 * each transaction is real.
 */
final class PostgresPromotionWriteLockTest extends TestCase
{
    private Connection $connection;
    private Connection $other;

    protected function setUp(): void
    {
        $this->connection = self::connect();
        $this->other = self::connect();
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

    private static function connect(): Connection
    {
        $env = static fn (string $name): string => \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : (getenv($name) ?: throw new \RuntimeException($name.' is not set.'));

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $env('SHOP_DB_HOST'),
            'dbname' => $env('SHOP_DB_NAME').'_test',
            'user' => $env('SHOP_DB_USER'),
            'password' => $env('SHOP_DB_PASSWORD'),
        ], new DbalConfiguration());
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
