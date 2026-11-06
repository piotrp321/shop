<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration on the real PostgreSQL table, in the transaction DAMA rolls back after each test: the
 * table is first put back to its shape without the slug (PostgreSQL DDL is transactional), rows are inserted, the
 * migration's SQL is executed and the result is read. Nothing is committed, so shop_test stays at the current schema.
 */
final class CategorySlugMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261106120000AddCatalogCategorySlug.php';
    private const string ROOT_ID = '00000000-0000-4000-8000-000000000001';
    private const string CHILD_ID = '00000000-0000-4000-8000-000000000002';
    private const string OTHER_ROOT_ID = '00000000-0000-4000-8000-000000000003';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testUpFillsExistingRowsWithTheirIdAndMakesTheColumnRequired(): void
    {
        $this->useShapeWithoutSlug();
        $this->insert(self::ROOT_ID, null, '/'.self::ROOT_ID.'/');
        $this->insert(self::OTHER_ROOT_ID, null, '/'.self::OTHER_ROOT_ID.'/');
        $this->insert(self::CHILD_ID, self::ROOT_ID, '/'.self::ROOT_ID.'/'.self::CHILD_ID.'/');

        $this->runMigration('up');

        self::assertSame(
            [self::ROOT_ID => self::ROOT_ID, self::CHILD_ID => self::CHILD_ID, self::OTHER_ROOT_ID => self::OTHER_ROOT_ID],
            $this->connection->fetchAllKeyValue('SELECT id, slug FROM catalog_category ORDER BY id'),
        );
        self::assertSame('NO', $this->connection->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'catalog_category' AND column_name = 'slug'",
        ));
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'catalog_category' AND indexdef LIKE '%slug%' ORDER BY indexname",
        );
        self::assertSame([
            'catalog_category_parent_slug_key' => 'CREATE UNIQUE INDEX catalog_category_parent_slug_key ON public.catalog_category USING btree (parent_id, slug)',
            'catalog_category_root_slug_key' => 'CREATE UNIQUE INDEX catalog_category_root_slug_key ON public.catalog_category USING btree (slug) WHERE (parent_id IS NULL)',
        ], $indexes);
    }

    public function testDownAfterUpRestoresTheShapeWithoutTheSlug(): void
    {
        $this->useShapeWithoutSlug();
        $this->insert(self::ROOT_ID, null, '/'.self::ROOT_ID.'/');
        $this->runMigration('up');

        $this->runMigration('down');

        self::assertFalse($this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'catalog_category' AND column_name = 'slug'",
        ));
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM catalog_category'));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM pg_indexes WHERE tablename = 'catalog_category' AND indexdef LIKE '%slug%'"));
    }

    private function useShapeWithoutSlug(): void
    {
        // Dropping the column drops the indexes on it as well.
        $this->connection->executeStatement('ALTER TABLE catalog_category DROP COLUMN IF EXISTS slug');
    }

    private function insert(string $id, ?string $parentId, string $path): void
    {
        $this->connection->executeStatement(
            'INSERT INTO catalog_category (id, name, path, parent_id) VALUES (?, ?, ?, ?)',
            [$id, 'Category '.$id, $path, $parentId],
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
