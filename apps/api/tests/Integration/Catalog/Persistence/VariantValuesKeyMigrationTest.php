<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that stops PostgreSQL generating values_key, on the real table, in the transaction DAMA
 * rolls back after each test (PostgreSQL DDL is transactional). Nothing is committed.
 */
final class VariantValuesKeyMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261110090000StopGeneratingVariantValuesKey.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const string PRODUCT_ID = '11111111-1111-4111-8111-111111111111';
    private const string INDEX_DEFINITION = 'CREATE UNIQUE INDEX catalog_product_variant_values_key ON public.catalog_product_variant USING btree (product_id, values_key)';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [self::CATEGORY_ID, 'Category', 'category', '/'.self::CATEGORY_ID.'/']);
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, version, status, updated_at, variant_axes)
             VALUES (?, 'first', 'First', 'Short', ?, 'standard', '[]'::jsonb, 1, 'draft', now(), '[\"Kolor\", \"Rozmiar\"]'::jsonb)",
            [self::PRODUCT_ID, self::CATEGORY_ID],
        );
    }

    public function testUpKeepsTheKeysAndTheIndexWithoutRewritingTheTableAndAllowsNull(): void
    {
        $this->useGeneratedColumn();
        $this->insertVariant('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 0, '["Żółty", "XL"]');
        $this->insertVariant('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 1, '["İ", "S"]');
        $keys = $this->keys();
        self::assertSame(['["żółty", "xl"]', '["i", "s"]'], $keys);
        $fileNode = $this->fileNode();

        $this->runMigration('up');

        self::assertSame($keys, $this->keys());
        self::assertSame($fileNode, $this->fileNode(), 'the table is not rewritten');
        self::assertSame(['is_generated' => 'NEVER', 'generation_expression' => null, 'is_nullable' => 'YES'], $this->column());
        self::assertSame(self::INDEX_DEFINITION, $this->indexDefinition());

        $this->insertVariant('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 2, '["Czarny", "M"]');
        self::assertSame(['["żółty", "xl"]', '["i", "s"]', null], $this->keys());
    }

    public function testDownRestoresTheGeneratedColumnAndItsIndex(): void
    {
        // The test database is migrated, so the column already has the shape after the migration.
        $this->connection->executeStatement(
            'INSERT INTO catalog_product_variant (id, product_id, position, option_values, values_key) VALUES (?, ?, 0, CAST(? AS jsonb), ?)',
            ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', self::PRODUCT_ID, '["Żółty", "XL"]', '["żółty","xl"]'],
        );
        $this->insertVariant('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 1, '["Czarny", "M"]');

        $this->runMigration('down');

        self::assertSame(['is_generated' => 'ALWAYS', 'generation_expression' => 'lower((option_values)::text)', 'is_nullable' => 'NO'], $this->column());
        self::assertSame(['["żółty", "xl"]', '["czarny", "m"]'], $this->keys());
        self::assertSame(self::INDEX_DEFINITION, $this->indexDefinition());
    }

    /**
     * The shape of the column before the migration, as Version20261104120000CreateCatalogProductVariants creates it.
     */
    private function useGeneratedColumn(): void
    {
        $this->connection->executeStatement('ALTER TABLE catalog_product_variant DROP COLUMN values_key');
        $this->connection->executeStatement('ALTER TABLE catalog_product_variant ADD values_key TEXT GENERATED ALWAYS AS (lower(option_values::text)) STORED NOT NULL');
        $this->connection->executeStatement('CREATE UNIQUE INDEX catalog_product_variant_values_key ON catalog_product_variant (product_id, values_key)');
    }

    private function insertVariant(string $id, int $position, string $optionValues): void
    {
        $this->connection->executeStatement(
            'INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, ?, CAST(? AS jsonb))',
            [$id, self::PRODUCT_ID, $position, $optionValues],
        );
    }

    /**
     * @return list<string|null>
     */
    private function keys(): array
    {
        /** @var list<string|null> */
        return $this->connection->fetchFirstColumn('SELECT values_key FROM catalog_product_variant WHERE product_id = ? ORDER BY position', [self::PRODUCT_ID]);
    }

    private function fileNode(): mixed
    {
        return $this->connection->fetchOne("SELECT pg_relation_filenode('catalog_product_variant')");
    }

    /**
     * @return array<string, mixed>|false
     */
    private function column(): array|false
    {
        return $this->connection->fetchAssociative(
            "SELECT is_generated, generation_expression, is_nullable FROM information_schema.columns WHERE table_name = 'catalog_product_variant' AND column_name = 'values_key'",
        );
    }

    private function indexDefinition(): mixed
    {
        return $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'catalog_product_variant_values_key'");
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
