<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that adds the brand, the description and the parameters of a product on the real PostgreSQL
 * table, in the transaction DAMA rolls back after each test: the columns are first dropped (PostgreSQL DDL is
 * transactional), products are inserted, the migration's SQL is executed and the result is read. Nothing is committed.
 */
final class ProductContentMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261125100000AddProductContent.php';
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

    public function testExistingProductsGetNoBrandNoDescriptionAndNoParametersAndNothingElseChanges(): void
    {
        $this->dropTheColumns();
        foreach (self::PRODUCT_IDS as $id) {
            $this->insertProduct($id);
        }
        $before = $this->otherColumns();

        $this->runMigration('up');

        self::assertSame(
            array_map(static fn (string $id): array => ['id' => $id, 'brand' => '', 'description' => '', 'parameters' => '[]'], self::PRODUCT_IDS),
            $this->connection->fetchAllAssociative('SELECT id, brand, description, parameters::text AS parameters FROM catalog_product ORDER BY id'),
        );
        self::assertSame($before, $this->otherColumns(), 'no other column changed');
    }

    public function testTheColumnsAreRequiredWithTheirDefaultsAndTheBrandHasTheLimitOfItsLength(): void
    {
        $this->dropTheColumns();

        $this->runMigration('up');

        self::assertSame(
            [
                'brand' => ['NO', 'character varying', 60, "''::character varying"],
                'description' => ['NO', 'text', null, "''::text"],
                'parameters' => ['NO', 'jsonb', null, "'[]'::jsonb"],
            ],
            array_map(
                static fn (array $row): array => [$row['is_nullable'], $row['data_type'], $row['character_maximum_length'], $row['column_default']],
                $this->connection->fetchAllAssociativeIndexed(
                    "SELECT column_name, is_nullable, data_type, character_maximum_length, column_default FROM information_schema.columns
                     WHERE table_name = 'catalog_product' AND column_name IN ('brand', 'description', 'parameters') ORDER BY column_name",
                ),
            ),
        );
    }

    public function testDownRemovesTheColumns(): void
    {
        $this->dropTheColumns();
        $this->insertProduct(self::PRODUCT_IDS[0]);
        $this->runMigration('up');

        $this->runMigration('down');

        self::assertFalse($this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'catalog_product' AND column_name IN ('brand', 'description', 'parameters')",
        ));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    private function dropTheColumns(): void
    {
        $this->connection->executeStatement('ALTER TABLE catalog_product DROP COLUMN IF EXISTS brand, DROP COLUMN IF EXISTS description, DROP COLUMN IF EXISTS parameters');
    }

    private function insertProduct(string $id): void
    {
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, variant_axes, status, updated_at, version)
             VALUES (?, ?, 'Name', '', ?, 'standard', '[]'::jsonb, '[]'::jsonb, 'published', now(), 4)",
            [$id, 'product-'.substr($id, 0, 8), self::CATEGORY_ID],
        );
    }

    /**
     * @return list<array<string, mixed>> every column of every product except the new ones
     */
    private function otherColumns(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT (to_jsonb(p) - 'brand' - 'description' - 'parameters')::text AS row FROM catalog_product p ORDER BY id",
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
