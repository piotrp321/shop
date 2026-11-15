<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Persistence;

use App\Tests\Integration\Inventory\IndependentConnections;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migrations of the indexes of the sorted lists on a connection of their own, outside the DAMA
 * transaction: CREATE INDEX CONCURRENTLY cannot run in a transaction block, so the migrations are not transactional and
 * every statement commits. A CREATE INDEX CONCURRENTLY that fails leaves an INVALID index of its name behind; the
 * retried migration (a retried Job `migrate`) has to drop it and build the index anew, not skip it on IF NOT EXISTS.
 *
 * The invalid index is made the way a stopped Job leaves it: another transaction holds a write lock on the table, so
 * the build waits for it and gives up on the lock timeout. The test leaves the shop_test schema as the migrations left
 * it: after each test the migration runs once more.
 */
final class ConcurrentIndexMigrationsTest extends KernelTestCase
{
    use IndependentConnections;

    private const string CATALOG = 'Version20261115130000IndexCatalogProductUpdatedAt';
    private const string ORDERS = 'Version20261115130100IndexOrderTotals';
    private const string ORDER_SEARCH = 'Version20261115140000IndexOrderSearch';

    private Connection $connection;
    private string $migrationClass = '';

    protected function setUp(): void
    {
        $this->connection = $this->independentConnection();
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, array<string, string>}> migration, table,
     *                                                                                               index => the columns (and predicate) to build it with, index => the end of its definition
     */
    public static function migrations(): iterable
    {
        yield 'the product indexes of the last change' => [
            self::CATALOG,
            'catalog_product',
            ['catalog_product_updated_at_id_idx' => '(updated_at, id)', 'catalog_product_draft_updated_at_id_idx' => "(updated_at, id) WHERE status = 'draft'"],
            ['catalog_product_updated_at_id_idx' => 'USING btree (updated_at, id)', 'catalog_product_draft_updated_at_id_idx' => "USING btree (updated_at, id) WHERE ((status)::text = 'draft'::text)"],
        ];
        yield 'the order indexes of the amount' => [
            self::ORDERS,
            'ordering_order',
            ['ordering_order_total_gross_id_idx' => '(total_gross, id)', 'ordering_order_status_total_gross_id_idx' => '(status, total_gross, id)'],
            ['ordering_order_total_gross_id_idx' => 'USING btree (total_gross, id)', 'ordering_order_status_total_gross_id_idx' => 'USING btree (status, total_gross, id)'],
        ];
        yield 'the order index of the search by fragment' => [
            self::ORDER_SEARCH,
            'ordering_order',
            ['ordering_order_search_trgm_idx' => "USING gin (lower(number || E'\\x1f' || email || E'\\x1f' || full_name) gin_trgm_ops)"],
            ['ordering_order_search_trgm_idx' => "USING gin (lower((((((number)::text || '\x1f'::text) || (email)::text) || '\x1f'::text) || (full_name)::text)) gin_trgm_ops)"],
        ];
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, array<string, string>}> the migrations of more than one index
     */
    public static function migrationsOfSeveralIndexes(): iterable
    {
        foreach (self::migrations() as $label => $migration) {
            if (\count($migration[2]) > 1) {
                yield $label => $migration;
            }
        }
    }

    /**
     * @param array<string, string>           $builds
     * @param array<string, non-empty-string> $definitions
     */
    #[DataProvider('migrations')]
    public function testTheMigrationIsNotTransactional(string $class, string $table, array $builds, array $definitions): void
    {
        $this->migrationClass = $class;

        self::assertFalse($this->migration()->isTransactional());
    }

    /**
     * @param array<string, string>           $builds
     * @param array<string, non-empty-string> $definitions
     */
    #[DataProvider('migrations')]
    public function testARetryAfterAFailedConcurrentBuildReplacesTheInvalidIndexes(string $class, string $table, array $builds, array $definitions): void
    {
        $this->migrationClass = $class;
        foreach ($builds as $name => $build) {
            $this->connection->executeStatement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            $this->leaveAnInvalidIndex($name, $table, $build);
            self::assertSame([$name => false], $this->validity([$name]));
        }

        $this->runMigration();

        self::assertSame(array_fill_keys(array_keys($builds), true), $this->validity(array_keys($builds)));
        foreach ($definitions as $name => $end) {
            self::assertStringEndsWith($end, $this->definition($name));
        }
    }

    /**
     * @param array<string, string>           $builds
     * @param array<string, non-empty-string> $definitions
     */
    #[DataProvider('migrationsOfSeveralIndexes')]
    public function testARetryAfterTheFirstIndexWasBuiltBuildsTheOtherAndKeepsTheFirst(string $class, string $table, array $builds, array $definitions): void
    {
        $this->migrationClass = $class;
        $names = array_keys($builds);
        $this->connection->executeStatement('DROP INDEX CONCURRENTLY IF EXISTS '.$names[1]);
        $oid = $this->oid($names[0]);

        $this->runMigration();

        self::assertSame($oid, $this->oid($names[0]), 'a valid index is not built again');
        self::assertSame(array_fill_keys($names, true), $this->validity($names));
    }

    /**
     * @param array<string, string>           $builds
     * @param array<string, non-empty-string> $definitions
     */
    #[DataProvider('migrations')]
    public function testARunOnIndexesThatAreThereChangesNothing(string $class, string $table, array $builds, array $definitions): void
    {
        $this->migrationClass = $class;
        $oids = array_map($this->oid(...), array_keys($builds));

        $this->runMigration();

        self::assertSame($oids, array_map($this->oid(...), array_keys($builds)));
    }

    /**
     * On a connection of its own: the hook of IndependentConnections may have closed the one of the test already.
     */
    #[After]
    protected function restoreTheIndexes(): void
    {
        if ('' === $this->migrationClass) {
            return;
        }
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        $connection = DriverManager::getConnection($params);
        try {
            $this->runMigration($connection);
        } finally {
            $connection->close();
        }
    }

    /**
     * What a CREATE INDEX CONCURRENTLY that gave up leaves: an index of the name marked INVALID. Here a second
     * transaction holds a write lock on the table, so the build waits for it and fails on the lock timeout.
     */
    private function leaveAnInvalidIndex(string $name, string $table, string $build): void
    {
        $blocker = $this->independentConnection();
        $blocker->beginTransaction();
        $blocker->executeStatement("LOCK TABLE {$table} IN ROW EXCLUSIVE MODE");
        $builder = $this->independentConnection();
        $builder->executeStatement("SET lock_timeout = '300ms'");
        try {
            $builder->executeStatement("CREATE INDEX CONCURRENTLY {$name} ON {$table} {$build}");
            self::fail('The build waits for the writer and must fail on the lock timeout.');
        } catch (DriverException $timedOut) {
            self::assertSame('55P03', $timedOut->getSQLState(), $timedOut->getMessage());
        } finally {
            $blocker->rollBack();
        }
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, bool> index name => valid
     */
    private function validity(array $names): array
    {
        $validity = [];
        foreach ($names as $name) {
            $valid = $this->connection->fetchOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$name]);
            self::assertIsBool($valid, $name);
            $validity[$name] = $valid;
        }

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
        $file = __DIR__.'/../../../../migrations/'.$this->migrationClass.'.php';
        self::assertFileExists($file);
        require_once $file;
        $class = 'DoctrineMigrations\\'.$this->migrationClass;
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
