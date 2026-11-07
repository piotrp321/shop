<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that adds the variants on the real PostgreSQL tables, in the transaction DAMA rolls back
 * after each test: the schema is first put back to its shape before the migration (PostgreSQL DDL is transactional),
 * products are inserted, the migration's SQL is executed and the result is read. Nothing is committed.
 *
 * The ids of the products are the ids Pricing, Inventory and Cart keep today in their product_id columns, so a
 * variant with the id of its product is what keeps those rows pointing at the right thing.
 */
final class ProductVariantsMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261104120000CreateCatalogProductVariants.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const string OTHER_CATEGORY_ID = '00000000-0000-4000-8000-000000000002';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        foreach ([self::CATEGORY_ID, self::OTHER_CATEGORY_ID] as $id) {
            $this->connection->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [$id, 'Category '.$id, $id, '/'.$id.'/']);
        }
    }

    public function testEveryExistingProductGetsOneDefaultVariantWithItsOwnId(): void
    {
        $this->useShapeBeforeVariants();
        $products = [
            '11111111-1111-4111-8111-111111111111' => 'first',
            '22222222-2222-4222-8222-222222222222' => 'second',
            'ffffffff-ffff-4fff-bfff-ffffffffffff' => 'last',
        ];
        foreach ($products as $id => $slug) {
            $this->insertProduct($id, $slug);
        }
        $before = $this->productRows();

        $this->runMigration('up');

        $variants = $this->connection->fetchAllAssociative(
            'SELECT id, product_id, position, option_values::text AS option_values, values_key FROM catalog_product_variant ORDER BY id',
        );
        self::assertSame(
            array_map(static fn (string $id): array => ['id' => $id, 'product_id' => $id, 'position' => 0, 'option_values' => '[]', 'values_key' => '[]'], array_keys($products)),
            $variants,
        );
        self::assertSame(['[]', '[]', '[]'], $this->connection->fetchFirstColumn('SELECT variant_axes::text FROM catalog_product ORDER BY id'));
        self::assertSame($before, $this->productRows(), 'no other column of any product changed');
    }

    public function testNoOtherDataChanges(): void
    {
        $this->useShapeBeforeVariants();
        $this->insertProduct('11111111-1111-4111-8111-111111111111', 'first', additionalCategoryId: self::OTHER_CATEGORY_ID);
        $categories = $this->connection->fetchAllAssociative('SELECT * FROM catalog_category ORDER BY id');
        $assignments = $this->connection->fetchAllAssociative('SELECT * FROM catalog_product_category ORDER BY product_id, category_id');
        self::assertCount(1, $assignments);

        $this->runMigration('up');

        self::assertSame($categories, $this->connection->fetchAllAssociative('SELECT * FROM catalog_category ORDER BY id'));
        self::assertSame($assignments, $this->connection->fetchAllAssociative('SELECT * FROM catalog_product_category ORDER BY product_id, category_id'));
    }

    public function testMigrationOnADatabaseWithoutProductsCreatesNoVariants(): void
    {
        $this->useShapeBeforeVariants();

        $this->runMigration('up');

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_variant'));
    }

    public function testDownRemovesTheVariantsAndTheAxes(): void
    {
        $this->insertProduct('11111111-1111-4111-8111-111111111111', 'first');

        $this->runMigration('down');

        self::assertFalse($this->hasTable('catalog_product_variant'));
        self::assertFalse($this->hasColumn('catalog_product', 'variant_axes'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    private function useShapeBeforeVariants(): void
    {
        $this->connection->executeStatement('DROP TABLE catalog_product_variant');
        $this->connection->executeStatement('ALTER TABLE catalog_product DROP COLUMN variant_axes');
    }

    private function insertProduct(string $id, string $slug, ?string $additionalCategoryId = null): void
    {
        $hasAxes = $this->hasColumn('catalog_product', 'variant_axes');
        $this->connection->executeStatement(
            \sprintf(
                "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, version%s)
                 VALUES (?, ?, ?, 'Short', ?, 'standard', '[{\"key\": \"originals/a.jpg\", \"alt\": \"A\"}]'::jsonb, 3%s)",
                $hasAxes ? ', variant_axes' : '',
                $hasAxes ? ", '[]'::jsonb" : '',
            ),
            [$id, $slug, 'Name '.$slug, self::CATEGORY_ID],
        );
        if (null !== $additionalCategoryId) {
            $this->connection->executeStatement('INSERT INTO catalog_product_category (product_id, category_id) VALUES (?, ?)', [$id, $additionalCategoryId]);
        }
    }

    /**
     * @return list<array<string, mixed>> every column of every product except the new one
     */
    private function productRows(): array
    {
        return $this->connection->fetchAllAssociative("SELECT (to_jsonb(p) - 'variant_axes')::text AS row FROM catalog_product p ORDER BY id");
    }

    private function hasTable(string $name): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM information_schema.tables WHERE table_name = ?', [$name]);
    }

    private function hasColumn(string $table, string $name): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $name],
        );
    }

    private function runMigration(string $direction): void
    {
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
