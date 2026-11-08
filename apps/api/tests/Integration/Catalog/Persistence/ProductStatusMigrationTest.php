<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that adds the product status on the real PostgreSQL table, in the transaction DAMA rolls
 * back after each test: the table is first put back to its shape before the migration (PostgreSQL DDL is
 * transactional), products are inserted, the migration's SQL is executed and the result is read. Nothing is committed.
 */
final class ProductStatusMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261107120000AddCatalogProductStatus.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const array PRODUCT_IDS = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [self::CATEGORY_ID, 'Category', 'category', '/'.self::CATEGORY_ID.'/']);
    }

    public function testExistingProductsArePublishedAtTheTimeOfTheMigrationWithoutAnActor(): void
    {
        $this->useShapeBeforeStatus();
        foreach (self::PRODUCT_IDS as $id) {
            $this->insertProduct($id);
        }
        $before = $this->otherColumns();

        $this->runMigration('up');

        // now() is the time of the transaction the migration ran in; the columns keep whole seconds.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, status, first_published_at = now()::timestamptz(0) AS published_now, updated_at = now()::timestamptz(0) AS updated_now, updated_by FROM catalog_product ORDER BY id',
        );
        self::assertSame(
            array_map(static fn (string $id): array => ['id' => $id, 'status' => 'published', 'published_now' => true, 'updated_now' => true, 'updated_by' => null], self::PRODUCT_IDS),
            $rows,
        );
        self::assertSame($before, $this->otherColumns(), 'no other column changed');
    }

    public function testStatusAndUpdatedAtAreRequiredAndTheDraftsHaveAPartialIndexOnNameAndId(): void
    {
        $this->useShapeBeforeStatus();

        $this->runMigration('up');

        self::assertSame(
            ['first_published_at' => 'YES', 'status' => 'NO', 'updated_at' => 'NO', 'updated_by' => 'YES'],
            $this->connection->fetchAllKeyValue(
                "SELECT column_name, is_nullable FROM information_schema.columns WHERE table_name = 'catalog_product'
                 AND column_name IN ('status', 'first_published_at', 'updated_by', 'updated_at') ORDER BY column_name",
            ),
        );
        self::assertSame(
            "CREATE INDEX catalog_product_draft_name_id_idx ON public.catalog_product USING btree (name, id) WHERE ((status)::text = 'draft'::text)",
            $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'catalog_product_draft_name_id_idx'"),
        );
    }

    public function testDownRemovesTheColumnsAndTheIndex(): void
    {
        $this->useShapeBeforeStatus();
        $this->insertProduct(self::PRODUCT_IDS[0]);
        $this->runMigration('up');

        $this->runMigration('down');

        self::assertFalse($this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'catalog_product'
             AND column_name IN ('status', 'first_published_at', 'updated_by', 'updated_at')",
        ));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM pg_indexes WHERE indexname = 'catalog_product_draft_name_id_idx'"));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    private function useShapeBeforeStatus(): void
    {
        // Dropping the status drops the partial index on it as well.
        $this->connection->executeStatement(
            'ALTER TABLE catalog_product DROP COLUMN IF EXISTS status, DROP COLUMN IF EXISTS first_published_at, DROP COLUMN IF EXISTS updated_by, DROP COLUMN IF EXISTS updated_at',
        );
    }

    private function insertProduct(string $id): void
    {
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, variant_axes, version)
             VALUES (?, ?, 'Name', '', ?, 'standard', '[]'::jsonb, '[]'::jsonb, 4)",
            [$id, 'product-'.substr($id, 0, 8), self::CATEGORY_ID],
        );
    }

    /**
     * @return list<array<string, mixed>> every column of every product except the new ones
     */
    private function otherColumns(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT (to_jsonb(p) - 'status' - 'first_published_at' - 'updated_by' - 'updated_at')::text AS row FROM catalog_product p ORDER BY id",
        );
    }

    private function runMigration(string $direction): void
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;
        $class = 'DoctrineMigrations\\'.basename(self::MIGRATION_FILE, '.php');
        self::assertTrue(class_exists($class), $class);
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
