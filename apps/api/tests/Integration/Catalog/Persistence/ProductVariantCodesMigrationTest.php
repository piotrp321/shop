<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that adds the SKU and the EAN of a variant on the real PostgreSQL table, in the transaction DAMA
 * rolls back after each test: the columns (and the unique index of the SKU with them) are first dropped (PostgreSQL DDL is
 * transactional), variants are inserted, the migration's SQL is executed and the result is read. Nothing is committed.
 * The index has its own migration and its own test (ProductVariantSkuIndexMigrationTest).
 */
final class ProductVariantCodesMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261125110000AddVariantCodes.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const string PRODUCT_ID = '11111111-1111-4111-8111-111111111111';
    private const array VARIANT_IDS = ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [self::CATEGORY_ID, 'Category', 'category', '/'.self::CATEGORY_ID.'/']);
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, variant_axes, status, updated_at, version)
             VALUES (?, 'product', 'Name', '', ?, 'standard', '[]'::jsonb, '[\"Kolor\"]'::jsonb, 'published', now(), 4)",
            [self::PRODUCT_ID, self::CATEGORY_ID],
        );
    }

    public function testTheVariantsStoredBeforeHaveNoCodesAndNothingElseChanges(): void
    {
        $this->dropTheColumns();
        $this->insertVariants();
        $before = $this->otherColumns();

        $this->runMigration('up');

        self::assertSame(
            array_map(static fn (string $id): array => ['id' => $id, 'sku' => null, 'ean' => null], self::VARIANT_IDS),
            $this->connection->fetchAllAssociative('SELECT id, sku, ean FROM catalog_product_variant ORDER BY position'),
        );
        self::assertSame($before, $this->otherColumns(), 'no other column changed');
    }

    public function testTheColumnsAreOptionalAndHaveTheLimitsOfTheirLength(): void
    {
        $this->dropTheColumns();

        $this->runMigration('up');

        self::assertSame(
            [
                'ean' => ['YES', 'character varying', 14],
                'sku' => ['YES', 'character varying', 40],
            ],
            array_map(
                static fn (array $row): array => [$row['is_nullable'], $row['data_type'], $row['character_maximum_length']],
                $this->connection->fetchAllAssociativeIndexed(
                    "SELECT column_name, is_nullable, data_type, character_maximum_length FROM information_schema.columns
                     WHERE table_name = 'catalog_product_variant' AND column_name IN ('sku', 'ean') ORDER BY column_name",
                ),
            ),
        );
    }

    public function testDownRemovesTheColumnsAndKeepsTheVariants(): void
    {
        $this->dropTheColumns();
        $this->insertVariants();
        $this->runMigration('up');

        $this->runMigration('down');

        self::assertFalse($this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'catalog_product_variant' AND column_name IN ('sku', 'ean')",
        ));
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_variant'));
    }

    private function dropTheColumns(): void
    {
        $this->connection->executeStatement('DROP INDEX IF EXISTS catalog_product_variant_sku_key');
        $this->connection->executeStatement('ALTER TABLE catalog_product_variant DROP COLUMN IF EXISTS sku, DROP COLUMN IF EXISTS ean');
    }

    private function insertVariants(): void
    {
        foreach (self::VARIANT_IDS as $position => $id) {
            $this->connection->executeStatement(
                'INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, ?, ?::jsonb)',
                [$id, self::PRODUCT_ID, $position, json_encode(['wartość '.$position], \JSON_THROW_ON_ERROR)],
            );
        }
    }

    /**
     * @return list<array<string, mixed>> every column of every variant except the new ones
     */
    private function otherColumns(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT (to_jsonb(v) - 'sku' - 'ean')::text AS row FROM catalog_product_variant v ORDER BY position",
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
