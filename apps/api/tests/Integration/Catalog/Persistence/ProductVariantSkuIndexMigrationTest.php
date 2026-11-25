<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Tests\Integration\Inventory\IndependentConnections;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\After;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration of the unique index of the SKU on a connection of its own, outside the DAMA transaction:
 * CREATE UNIQUE INDEX CONCURRENTLY cannot run in a transaction block, so the migration is not transactional and every
 * statement commits (docs/features/product-data/spec.md, "Model i persystencja"; apps/api/CLAUDE.md, "Infrastruktura").
 * A failed build leaves an INVALID index of its name behind; the retried migration (a retried Job `migrate`) has to drop
 * it and build the index anew, not skip it on IF NOT EXISTS.
 *
 * The test leaves the shop_test schema as the migrations left it: after each test the migration runs once more.
 */
final class ProductVariantSkuIndexMigrationTest extends KernelTestCase
{
    use IndependentConnections;

    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261125110100IndexVariantSku.php';
    private const string INDEX = 'catalog_product_variant_sku_key';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-0000000000a1';
    private const array PRODUCT_IDS = ['aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000002'];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->independentConnection();
    }

    public function testTheMigrationIsNotTransactional(): void
    {
        self::assertFalse($this->migration()->isTransactional());
    }

    public function testTheIndexIsUniqueOverTheSkuAndAcceptsAnyNumberOfVariantsWithoutOne(): void
    {
        self::assertSame(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON public.catalog_product_variant USING btree (sku)',
            $this->definition(),
        );
        $this->insertProductsWithVariants([null, null, 'SAME-1']);

        try {
            $this->connection->executeStatement(
                "INSERT INTO catalog_product_variant (id, product_id, position, option_values, sku) VALUES ('cccccccc-0000-4000-8000-000000000009', ?, 9, '[]'::jsonb, 'SAME-1')",
                [self::PRODUCT_IDS[0]],
            );
            self::fail('Expected the unique index to refuse a second SKU.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString(self::INDEX, $e->getMessage());
        }
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM catalog_product_variant WHERE sku IS NULL AND product_id IN (?, ?)', self::PRODUCT_IDS));
    }

    public function testARetryAfterAFailedConcurrentBuildReplacesTheInvalidIndex(): void
    {
        $this->connection->executeStatement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        $this->leaveAnInvalidIndex();
        self::assertFalse($this->valid());

        $this->runMigration();

        self::assertTrue($this->valid());
        self::assertSame('CREATE UNIQUE INDEX '.self::INDEX.' ON public.catalog_product_variant USING btree (sku)', $this->definition());
    }

    public function testARunOnTheIndexThatIsThereChangesNothing(): void
    {
        $oid = $this->oid();

        $this->runMigration();

        self::assertSame($oid, $this->oid());
        self::assertTrue($this->valid());
    }

    /**
     * On a connection of its own: the hook of IndependentConnections may have closed the one of the test already.
     */
    #[After]
    protected function restoreTheIndex(): void
    {
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        $connection = DriverManager::getConnection($params);
        try {
            $connection->executeStatement('DELETE FROM catalog_product WHERE id IN (?, ?)', self::PRODUCT_IDS);
            $connection->executeStatement('DELETE FROM catalog_category WHERE id = ?', [self::CATEGORY_ID]);
            $this->runMigration($connection);
        } finally {
            $connection->close();
        }
    }

    /**
     * What a CREATE UNIQUE INDEX CONCURRENTLY interrupted by an error leaves: an index of the name marked INVALID. Here the
     * error is a duplicate over two committed rows.
     */
    private function leaveAnInvalidIndex(): void
    {
        $this->insertProductsWithVariants(['SAME-1', 'SAME-1']);
        try {
            $this->connection->executeStatement('CREATE UNIQUE INDEX CONCURRENTLY '.self::INDEX.' ON catalog_product_variant (sku)');
            self::fail('The unique build over duplicates must fail.');
        } catch (UniqueConstraintViolationException) {
        }
        $this->connection->executeStatement('DELETE FROM catalog_product WHERE id IN (?, ?)', self::PRODUCT_IDS);
    }

    /**
     * Two committed products with one variant each, and the given SKUs on further variants of the first one: the first
     * variant of every product gets the first two SKUs.
     *
     * @param list<string|null> $skus
     */
    private function insertProductsWithVariants(array $skus): void
    {
        $this->connection->executeStatement(
            "INSERT INTO catalog_category (id, name, slug, path) VALUES (?, 'Index test', 'index-test', ?) ON CONFLICT DO NOTHING",
            [self::CATEGORY_ID, '/'.self::CATEGORY_ID.'/'],
        );
        foreach (self::PRODUCT_IDS as $position => $id) {
            $this->connection->executeStatement(
                "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, variant_axes, status, updated_at, version)
                 VALUES (?, ?, 'Name', '', ?, 'standard', '[]'::jsonb, '[\"Kolor\"]'::jsonb, 'published', now(), 1) ON CONFLICT DO NOTHING",
                [$id, 'index-test-'.$position, self::CATEGORY_ID],
            );
        }
        foreach ($skus as $position => $sku) {
            $this->connection->executeStatement(
                "INSERT INTO catalog_product_variant (id, product_id, position, option_values, sku) VALUES (?, ?, ?, ?::jsonb, ?)",
                [\sprintf('dddddddd-0000-4000-8000-%012d', $position), self::PRODUCT_IDS[$position % 2], intdiv($position, 2), json_encode(['v'.$position], \JSON_THROW_ON_ERROR), $sku],
            );
        }
    }

    private function valid(): bool
    {
        $valid = $this->connection->fetchOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [self::INDEX]);
        self::assertIsBool($valid);

        return $valid;
    }

    private function definition(): string
    {
        $definition = $this->connection->fetchOne('SELECT indexdef FROM pg_indexes WHERE indexname = ?', [self::INDEX]);
        self::assertIsString($definition);

        return $definition;
    }

    private function oid(): int
    {
        $oid = $this->connection->fetchOne('SELECT oid FROM pg_class WHERE relname = ?', [self::INDEX]);
        self::assertIsInt($oid);

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
     * As Doctrine Migrations runs a non-transactional migration: up() plans the statements, each one then commits on its
     * own.
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
