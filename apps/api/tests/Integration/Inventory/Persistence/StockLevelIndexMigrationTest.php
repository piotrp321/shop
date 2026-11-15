<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use App\Tests\Integration\Inventory\IndependentConnections;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\After;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the real migration of the stock level indexes on a connection of its own, outside the DAMA transaction:
 * CREATE INDEX CONCURRENTLY cannot run in a transaction block, so the migration is not transactional and every
 * statement commits. A failed CREATE INDEX CONCURRENTLY leaves an INVALID index of its name behind; the retried
 * migration (a retried Job `migrate`) has to drop it and build the index anew, not skip it on IF NOT EXISTS.
 *
 * The test leaves the shop_test schema as the migrations left it: after each test the migration runs once more.
 */
final class StockLevelIndexMigrationTest extends KernelTestCase
{
    use IndependentConnections;

    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261115120100IndexStockLevels.php';
    private const string OUT_OF_STOCK = 'inventory_stock_item_out_of_stock_idx';
    private const string LOW_STOCK = 'inventory_stock_item_low_stock_idx';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->independentConnection();
    }

    public function testTheMigrationIsNotTransactional(): void
    {
        self::assertFalse($this->migration()->isTransactional());
    }

    public function testARetryAfterAFailedConcurrentBuildReplacesTheInvalidIndex(): void
    {
        $this->connection->executeStatement('DROP INDEX CONCURRENTLY IF EXISTS '.self::LOW_STOCK);
        $this->leaveAnInvalidIndex(self::LOW_STOCK);
        self::assertSame([self::LOW_STOCK => false], $this->validity([self::LOW_STOCK]));

        $this->runMigration();

        self::assertSame([self::LOW_STOCK => true, self::OUT_OF_STOCK => true], $this->validity([self::LOW_STOCK, self::OUT_OF_STOCK]));
        self::assertStringEndsWith(
            'USING btree (product_id) WHERE (((physical - reserved) >= 1) AND ((physical - reserved) <= 5))',
            $this->definition(self::LOW_STOCK),
        );
    }

    public function testARetryAfterTheFirstIndexWasBuiltBuildsTheOtherAndKeepsTheFirst(): void
    {
        $this->connection->executeStatement('DROP INDEX CONCURRENTLY IF EXISTS '.self::LOW_STOCK);
        $oid = $this->oid(self::OUT_OF_STOCK);

        $this->runMigration();

        self::assertSame($oid, $this->oid(self::OUT_OF_STOCK), 'a valid index is not built again');
        self::assertSame([self::LOW_STOCK => true, self::OUT_OF_STOCK => true], $this->validity([self::LOW_STOCK, self::OUT_OF_STOCK]));
    }

    public function testARunOnIndexesThatAreThereChangesNothing(): void
    {
        $oids = [$this->oid(self::OUT_OF_STOCK), $this->oid(self::LOW_STOCK)];

        $this->runMigration();

        self::assertSame($oids, [$this->oid(self::OUT_OF_STOCK), $this->oid(self::LOW_STOCK)]);
    }

    /**
     * On a connection of its own: the hook of IndependentConnections may have closed the one of the test already.
     */
    #[After]
    protected function restoreTheIndexes(): void
    {
        $connection = DriverManager::getConnection($this->connection->getParams());
        try {
            $this->runMigration($connection);
        } finally {
            $connection->close();
        }
    }

    /**
     * What a CREATE INDEX CONCURRENTLY interrupted by an error leaves: an index of the name marked INVALID. Here the
     * error is a duplicate of a unique build over two committed rows, which are deleted afterwards.
     */
    private function leaveAnInvalidIndex(string $name): void
    {
        $variants = [(string) Uuid::v7(), (string) Uuid::v7()];
        foreach ($variants as $variant) {
            $this->connection->executeStatement(
                'INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at) VALUES (?, 424242, 0, 1, now())',
                [$variant],
            );
            $this->committedVariantIds[] = $variant;
        }
        try {
            $this->connection->executeStatement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON inventory_stock_item (physical) WHERE physical = 424242");
            self::fail('The unique build over duplicates must fail.');
        } catch (UniqueConstraintViolationException) {
        }
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, bool> index name => valid, by name
     */
    private function validity(array $names): array
    {
        $validity = [];
        foreach ($names as $name) {
            $valid = $this->connection->fetchOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$name]);
            self::assertIsBool($valid, $name);
            $validity[$name] = $valid;
        }
        ksort($validity);

        return $validity;
    }

    private function definition(string $name): string
    {
        $definition = $this->connection->fetchOne('SELECT indexdef FROM pg_indexes WHERE indexname = ?', [$name]);
        self::assertIsString($definition);

        return $definition;
    }

    private function oid(string $name): int
    {
        $oid = $this->connection->fetchOne('SELECT oid FROM pg_class WHERE relname = ?', [$name]);
        self::assertIsInt($oid, $name);

        return $oid;
    }

    private function migration(?Connection $connection = null): AbstractMigration
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;
        $class = 'DoctrineMigrations\\'.basename(self::MIGRATION_FILE, '.php');
        self::assertTrue(class_exists($class), $class);
        $migration = new $class($connection ?? $this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        return $migration;
    }

    /**
     * As Doctrine Migrations runs a non-transactional migration: up() plans the statements, each one then commits on
     * its own.
     */
    private function runMigration(?Connection $connection = null): void
    {
        $connection ??= $this->connection;
        $migration = $this->migration($connection);
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
