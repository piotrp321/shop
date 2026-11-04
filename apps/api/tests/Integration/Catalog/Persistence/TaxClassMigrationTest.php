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
 * column is first put back to its previous shape (PostgreSQL DDL is transactional), rows are inserted, the
 * migration's SQL is executed and the result is read. Nothing is committed, so shop_test stays at the current schema.
 */
final class TaxClassMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261101090000RenameCatalogProductTaxCategoryToTaxClass.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement(
            \sprintf("INSERT INTO catalog_category (id, name, path) VALUES ('%s', 'Root', '/%1\$s/')", self::CATEGORY_ID),
        );
    }

    public function testUpRenamesTheColumnAndMapsTheFourOldValues(): void
    {
        $this->useOldShape();
        $this->insertProducts('tax_category', [
            'standard' => 'standard',
            'printed_publication' => 'printed_publication',
            'food' => 'food',
            'food_supplement' => 'food_supplement',
        ]);

        $this->runMigration('up');

        self::assertSame(
            [
                'food' => 'freeze_dried_meal',
                'food_supplement' => 'protein_powder',
                'printed_publication' => 'printed_book',
                'standard' => 'standard',
            ],
            $this->taxClassesBySlug(),
        );
        self::assertFalse($this->hasColumn('tax_category'));
    }

    public function testDownRestoresTheOldColumnMappingEveryClassToTheNearestOldCategory(): void
    {
        $classes = [
            'standard' => 'standard',
            'printed_book' => 'printed_publication',
            'printed_map' => 'printed_publication',
            'freeze_dried_meal' => 'food',
            'energy_bar' => 'food',
            'energy_gel' => 'food',
            'protein_powder' => 'food_supplement',
            'electrolyte_tablet' => 'food_supplement',
        ];
        $this->insertProducts('tax_class', array_combine(array_keys($classes), array_keys($classes)));

        $this->runMigration('down');

        self::assertFalse($this->hasColumn('tax_class'));
        ksort($classes);
        self::assertSame(
            $classes,
            $this->connection->fetchAllKeyValue('SELECT slug, tax_category FROM catalog_product ORDER BY slug'),
        );
    }

    private function useOldShape(): void
    {
        $this->connection->executeStatement('ALTER TABLE catalog_product RENAME COLUMN tax_class TO tax_category');
    }

    /**
     * @param array<string, string> $valueBySlug
     */
    private function insertProducts(string $column, array $valueBySlug): void
    {
        foreach ($valueBySlug as $slug => $value) {
            $this->connection->executeStatement(
                \sprintf(
                    "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, %s, images, variant_axes, version)
                     VALUES (gen_random_uuid(), ?, ?, '', ?, ?, '[]'::jsonb, '[]'::jsonb, 1)",
                    $column,
                ),
                [$slug, $slug, self::CATEGORY_ID, $value],
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function taxClassesBySlug(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue('SELECT slug, tax_class FROM catalog_product ORDER BY slug');

        return $rows;
    }

    private function hasColumn(string $name): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'catalog_product' AND column_name = ?",
            [$name],
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
