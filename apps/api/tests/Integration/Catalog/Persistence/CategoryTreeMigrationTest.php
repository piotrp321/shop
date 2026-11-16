<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration of the category management on the real tables, in the transaction DAMA rolls back after
 * each test (like CategorySlugMigrationTest): the columns and the redirect table are dropped first, rows are inserted
 * in the shape of the previous version, then the migration runs. It changes the schema only: the existing categories
 * get position 0 and version 1 from the defaults, so the tree keeps its order by name.
 */
final class CategoryTreeMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261115170000AddCategoryOrderAndRedirects.php';
    private const string ROOT_ID = '00000000-0000-4000-8000-000000000001';
    private const string CHILD_ID = '00000000-0000-4000-8000-000000000002';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testExistingCategoriesGetPositionZeroAndVersionOne(): void
    {
        $this->connection->executeStatement('DROP TABLE catalog_category_redirect');
        $this->connection->executeStatement('ALTER TABLE catalog_category DROP COLUMN position, DROP COLUMN version');
        $this->connection->executeStatement("INSERT INTO catalog_category (id, name, slug, path) VALUES (?, 'Sport', 'sport', ?)", [self::ROOT_ID, '/'.self::ROOT_ID.'/']);
        $this->connection->executeStatement("INSERT INTO catalog_category (id, name, slug, path, parent_id) VALUES (?, 'Buty', 'buty', ?, ?)", [self::CHILD_ID, '/'.self::ROOT_ID.'/'.self::CHILD_ID.'/', self::ROOT_ID]);

        $this->runMigration();

        self::assertSame(
            [['id' => self::ROOT_ID, 'position' => 0, 'version' => 1], ['id' => self::CHILD_ID, 'position' => 0, 'version' => 1]],
            $this->connection->fetchAllAssociative('SELECT id, position, version FROM catalog_category ORDER BY id'),
        );
    }

    public function testARedirectGoesWithItsCategory(): void
    {
        $this->connection->executeStatement('DROP TABLE catalog_category_redirect');
        $this->connection->executeStatement('ALTER TABLE catalog_category DROP COLUMN position, DROP COLUMN version');
        $this->runMigration();
        $this->connection->executeStatement("INSERT INTO catalog_category (id, name, slug, path) VALUES (?, 'Sport', 'sport', ?)", [self::ROOT_ID, '/'.self::ROOT_ID.'/']);
        $this->connection->executeStatement("INSERT INTO catalog_category_redirect (old_path, category_id) VALUES ('stary', ?)", [self::ROOT_ID]);

        $this->connection->executeStatement('DELETE FROM catalog_category WHERE id = ?', [self::ROOT_ID]);

        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM catalog_category_redirect'));
    }

    private function runMigration(): void
    {
        self::assertFileExists(self::MIGRATION_FILE);
        require_once self::MIGRATION_FILE;
        $class = 'DoctrineMigrations\\'.basename(self::MIGRATION_FILE, '.php');
        self::assertTrue(class_exists($class), $class);
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        self::assertTrue($migration->isTransactional());

        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
