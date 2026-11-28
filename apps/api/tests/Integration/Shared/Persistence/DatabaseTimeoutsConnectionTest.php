<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Persistence;

use App\Shared\Infrastructure\Persistence\Timeout\DatabaseTimeouts;
use App\Shared\Infrastructure\Persistence\Timeout\TimeoutMiddleware;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\After;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The limits of the session that TimeoutMiddleware sets on a connection (spec: docs/features/runtime-config/spec.md,
 * "Limity czasu w bazie"). The connections are real ones, outside the DAMA transaction, with small limits.
 */
final class DatabaseTimeoutsConnectionTest extends KernelTestCase
{
    private const string TABLE = 'database_timeouts_test';

    /** @var list<Connection> */
    private array $connections = [];

    private DatabaseTimeouts $timeouts;
    private Connection $control;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->timeouts = new DatabaseTimeouts(
            [
                DatabaseTimeouts::CONSOLE => ['statement' => 0, 'idle_in_transaction' => 0, 'lock' => 0],
                DatabaseTimeouts::HTTP => ['statement' => 1, 'idle_in_transaction' => 1, 'lock' => 0],
                DatabaseTimeouts::HTTP_LONG => ['statement' => 4, 'idle_in_transaction' => 1, 'lock' => 0],
                DatabaseTimeouts::WORKER => ['statement' => 2, 'idle_in_transaction' => 2, 'lock' => 0],
                DatabaseTimeouts::MIGRATION => ['statement' => 0, 'idle_in_transaction' => 0, 'lock' => 1],
            ],
            ['long_route'],
            [],
        );
        $this->control = $this->connection(withTheLimits: false);
        $this->control->executeStatement(\sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        $this->control->executeStatement(\sprintf('CREATE TABLE %s (id int PRIMARY KEY)', self::TABLE));
        $this->control->executeStatement(\sprintf('INSERT INTO %s VALUES (1)', self::TABLE));
    }

    #[After]
    protected function dropTheTable(): void
    {
        $this->control->executeStatement(\sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    public function testTheFirstStatementOfAConnectionHasTheLimitsOfTheCurrentProfile(): void
    {
        $connection = $this->connection();
        $this->timeouts->startRequest('catalog_product_list');

        self::assertSame(['1000', '1000', '0'], $this->limits($connection));
    }

    public function testTheLimitsOfAnOpenConnectionFollowTheProfileAndTheLimitOfARouteDoesNotPassToTheNextRequest(): void
    {
        $connection = $this->connection();

        $this->timeouts->startRequest('long_route');
        self::assertSame(['4000', '1000', '0'], $this->limits($connection));
        $this->timeouts->endRequest();
        $this->timeouts->startRequest('catalog_product_list');
        self::assertSame(['1000', '1000', '0'], $this->limits($connection));
        $this->timeouts->endRequest();
        self::assertSame(['0', '0', '0'], $this->limits($connection), 'a console command has none');
        $this->timeouts->useBase(DatabaseTimeouts::WORKER);
        self::assertSame(['2000', '2000', '0'], $this->limits($connection));
    }

    public function testAStatementOverTheLimitIsCancelledAndTheConnectionServesTheNextOne(): void
    {
        $connection = $this->connection();
        $this->timeouts->startRequest('catalog_product_list');

        $startedAt = microtime(true);
        try {
            $connection->executeQuery('SELECT pg_sleep(5)');
            self::fail('Expected the statement to be cancelled.');
        } catch (DriverException $cancelled) {
            self::assertSame('57014', $cancelled->getSQLState());
        }
        self::assertLessThan(2.5, microtime(true) - $startedAt);

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    public function testATransactionIdleOverTheLimitLosesTheConnectionAndItsLocks(): void
    {
        $connection = $this->connection();
        $this->timeouts->startRequest('catalog_product_list');
        $connection->beginTransaction();
        $connection->executeQuery(\sprintf('SELECT id FROM %s WHERE id = 1 FOR UPDATE', self::TABLE));
        self::assertTrue($this->lockIsHeld(), 'the row is locked while the transaction is open');

        usleep(2_200_000);

        self::assertFalse($this->lockIsHeld(), 'the lock is released with the connection');
        $this->expectException(DriverException::class);
        $connection->executeQuery('SELECT 1');
    }

    public function testALockWaitLongerThanTheLockTimeoutOfAMigrationFailsAndAStatementOfAMigrationHasNoLimit(): void
    {
        $holder = $this->connection(withTheLimits: false);
        $holder->beginTransaction();
        $holder->executeStatement(\sprintf('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE', self::TABLE));
        $migration = $this->connection();
        $this->timeouts->useBase(DatabaseTimeouts::MIGRATION);

        $startedAt = microtime(true);
        try {
            $migration->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN note text', self::TABLE));
            self::fail('Expected the wait for the lock to end with an error.');
        } catch (DriverException $refused) {
            self::assertSame('55P03', $refused->getSQLState());
        }
        self::assertLessThan(3.0, microtime(true) - $startedAt);
        $holder->rollBack();

        $migration->executeQuery('SELECT pg_sleep(1.5)');
        self::assertSame(['0', '0', '1000'], $this->limits($migration));
    }

    public function testAnIndexBuiltConcurrentlyWaitsForOlderTransactionsLongerThanTheLockTimeoutOfAMigration(): void
    {
        // A transaction that holds a snapshot of the table, as a query of the panel or a worker does; the server ends it after 2.5 s.
        $older = $this->connection(withTheLimits: false);
        $older->executeStatement("SET idle_in_transaction_session_timeout = '2500ms'");
        $older->beginTransaction();
        $older->executeQuery(\sprintf('SELECT 1 FROM %s LIMIT 1', self::TABLE));
        $migration = $this->connection();
        $this->timeouts->useBase(DatabaseTimeouts::MIGRATION);

        $startedAt = microtime(true);
        $migration->executeStatement(\sprintf('CREATE INDEX CONCURRENTLY %s_idx ON %s (id)', self::TABLE, self::TABLE));

        self::assertGreaterThan(1.5, microtime(true) - $startedAt, 'it waited for the older transaction, longer than the lock timeout of 1 s');
        self::assertSame(1, $migration->fetchOne('SELECT count(*) FROM pg_index WHERE indexrelid = CAST(? AS regclass) AND indisvalid', [self::TABLE.'_idx']));
        self::assertSame(['0', '0', '1000'], $this->limits($migration), 'the lock timeout is back for the next statement');
    }

    public function testTheLimitsComeBackAfterARollbackThatUndidTheirSetting(): void
    {
        $connection = $this->connection();
        $this->timeouts->startRequest('catalog_product_list');
        self::assertSame(['1000', '1000', '0'], $this->limits($connection));

        $connection->beginTransaction();
        $this->timeouts->startRequest('long_route');
        self::assertSame(['4000', '1000', '0'], $this->limits($connection), 'set inside the transaction');
        $connection->rollBack();

        self::assertSame(['4000', '1000', '0'], $this->limits($connection), 'set again after the rollback took it back');
    }

    private function connection(bool $withTheLimits = true): Connection
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $configuration = new DbalConfiguration();
        if ($withTheLimits) {
            $configuration->setMiddlewares([new TimeoutMiddleware($this->timeouts)]);
        }
        $connection = DriverManager::getConnection($params, $configuration);
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * @return list<string> statement_timeout, idle_in_transaction_session_timeout and lock_timeout in milliseconds
     */
    private function limits(Connection $connection): array
    {
        $limits = [];
        foreach (['statement_timeout', 'idle_in_transaction_session_timeout', 'lock_timeout'] as $name) {
            $setting = $connection->fetchOne('SELECT setting FROM pg_settings WHERE name = ?', [$name]);
            \assert(\is_string($setting));
            $limits[] = $setting;
        }

        return $limits;
    }

    private function lockIsHeld(): bool
    {
        try {
            $this->control->beginTransaction();
            $this->control->executeQuery(\sprintf('SELECT id FROM %s WHERE id = 1 FOR UPDATE NOWAIT', self::TABLE));
            $this->control->rollBack();

            return false;
        } catch (DriverException $locked) {
            $this->control->rollBack();
            self::assertSame('55P03', $locked->getSQLState());

            return true;
        }
    }
}
