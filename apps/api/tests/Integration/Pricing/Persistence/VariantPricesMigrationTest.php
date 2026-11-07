<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that keys prices by variant on the real PostgreSQL tables, in the transaction DAMA rolls
 * back after each test: the migration's own down() first puts the tables back to their shape before it (PostgreSQL
 * DDL is transactional), prices and history are inserted with product ids, the migration's up() is executed and the
 * result is read. Nothing is committed.
 *
 * The Catalog migration gave every product a default variant with the id of the product, so a price keyed by the old
 * product id is, after this migration, the price of that default variant. Here the variant id is the product id on
 * purpose: that is the data the migration meets.
 */
final class VariantPricesMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261104130000KeyPricesByVariant.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const string BOOK = '11111111-1111-4111-8111-111111111111';
    private const string TENT = '22222222-2222-4222-8222-222222222222';
    private const string HISTORY_1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string HISTORY_2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';
    private const string HISTORY_3 = '0192f0c4-7c1a-7c4e-9a6e-000000000003';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testEveryPriceAndHistoryEntryKeepsItsIdAndPointsToTheDefaultVariantOfItsProduct(): void
    {
        $this->runMigration('down');
        $this->insertCatalogProductWithDefaultVariant(self::BOOK, 'przewodnik');
        $this->insertCatalogProductWithDefaultVariant(self::TENT, 'namiot');
        $this->insertPrice(self::BOOK, 5490, 500, 'printed_book', 3);
        $this->insertPrice(self::TENT, 69900, 2300, 'standard', 1);
        $this->insertHistory(self::HISTORY_1, self::BOOK, 4990, 'seed');
        $this->insertHistory(self::HISTORY_2, self::BOOK, 5490, 'staff');
        $this->insertHistory(self::HISTORY_3, self::TENT, 69900, 'seed');

        $this->runMigration('up');

        self::assertSame(
            [
                ['variant_id' => self::BOOK, 'product_id' => self::BOOK, 'gross_amount' => 5490, 'version' => 3],
                ['variant_id' => self::TENT, 'product_id' => self::TENT, 'gross_amount' => 69900, 'version' => 1],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT p.variant_id, v.product_id, p.gross_amount, p.version FROM pricing_variant_price p
                 JOIN catalog_product_variant v ON v.id = p.variant_id AND v.option_values = \'[]\'::jsonb ORDER BY p.variant_id',
            ),
        );
        self::assertSame(
            [
                ['id' => self::HISTORY_1, 'variant_id' => self::BOOK, 'product_id' => self::BOOK, 'gross_amount' => 4990],
                ['id' => self::HISTORY_2, 'variant_id' => self::BOOK, 'product_id' => self::BOOK, 'gross_amount' => 5490],
                ['id' => self::HISTORY_3, 'variant_id' => self::TENT, 'product_id' => self::TENT, 'gross_amount' => 69900],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT h.id, h.variant_id, v.product_id, h.gross_amount FROM pricing_price_history h
                 JOIN catalog_product_variant v ON v.id = h.variant_id AND v.option_values = \'[]\'::jsonb ORDER BY h.id',
            ),
        );
    }

    public function testNoValueOfAnyColumnChanges(): void
    {
        $this->runMigration('down');
        $this->insertPrice(self::BOOK, 5490, 500, 'printed_book', 3);
        $this->insertHistory(self::HISTORY_1, self::BOOK, 4990, 'seed');
        $prices = $this->rows('pricing_product_price', 'product_id');
        $history = $this->rows('pricing_price_history', 'product_id');

        $this->runMigration('up');

        self::assertSame($prices, $this->rows('pricing_variant_price', 'variant_id'));
        self::assertSame($history, $this->rows('pricing_price_history', 'variant_id'));
        self::assertFalse($this->hasTable('pricing_product_price'));
        self::assertFalse($this->hasColumn('pricing_price_history', 'product_id'));
    }

    public function testTheHistoryIndexServesTheVariantNewestFirstAndThePrimaryKeyIsNamedAfterTheTable(): void
    {
        self::assertSame(
            'CREATE INDEX pricing_price_history_variant_changed_at_idx ON public.pricing_price_history USING btree (variant_id, changed_at DESC, id)',
            $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'pricing_price_history' AND indexname LIKE '%changed_at%'"),
        );
        self::assertSame(
            ['pricing_variant_price_pkey'],
            $this->connection->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid = 'pricing_variant_price'::regclass AND contype = 'p'"),
        );
    }

    public function testDownPutsTheProductKeysBack(): void
    {
        $this->insertVariantPrice(self::BOOK);

        $this->runMigration('down');

        self::assertSame([self::BOOK], $this->connection->fetchFirstColumn('SELECT product_id FROM pricing_product_price'));
        self::assertTrue($this->hasColumn('pricing_price_history', 'product_id'));
        self::assertFalse($this->hasTable('pricing_variant_price'));
    }

    private function insertCatalogProductWithDefaultVariant(string $id, string $slug): void
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM catalog_category WHERE id = ?', [self::CATEGORY_ID])) {
            $this->connection->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [self::CATEGORY_ID, 'Category', self::CATEGORY_ID, '/'.self::CATEGORY_ID.'/']);
        }
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, version, variant_axes)
             VALUES (?, ?, ?, '', ?, 'standard', '[]'::jsonb, 1, '[]'::jsonb)",
            [$id, $slug, 'Name '.$slug, self::CATEGORY_ID],
        );
        // As the Catalog migration left it: the default variant has the id of its product.
        $this->connection->executeStatement("INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, 0, '[]'::jsonb)", [$id, $id]);
    }

    private function insertPrice(string $productId, int $gross, int $basisPoints, string $taxClass, int $version): void
    {
        $this->connection->executeStatement(
            "INSERT INTO pricing_product_price (product_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at)
             VALUES (?, 'PLN', ?, ?, 0, ?, ?, ?, '2026-10-01 12:00:00+00')",
            [$productId, $gross, $gross, $basisPoints, $taxClass, $version],
        );
    }

    private function insertVariantPrice(string $variantId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO pricing_variant_price (variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at)
             VALUES (?, 'PLN', 100, 100, 0, 2300, 'standard', 1, '2026-10-01 12:00:00+00')",
            [$variantId],
        );
    }

    private function insertHistory(string $id, string $productId, int $gross, string $source): void
    {
        $this->connection->executeStatement(
            "INSERT INTO pricing_price_history (id, product_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, changed_at, source, actor_id, actor_name)
             VALUES (?, ?, 'PLN', ?, ?, 0, 2300, 'standard', '2026-10-01 12:00:00+00', ?, 'sub-1', 'Anna Admin')",
            [$id, $productId, $gross, $gross, $source],
        );
    }

    /**
     * @return list<string> every column of every row as JSON, the key column under the name `key`
     */
    private function rows(string $table, string $keyColumn): array
    {
        return array_map(
            static fn (mixed $row): string => \is_string($row) ? $row : '',
            $this->connection->fetchFirstColumn(\sprintf("SELECT ((to_jsonb(t) - '%2\$s') || jsonb_build_object('key', t.%2\$s))::text FROM %1\$s t ORDER BY 1", $table, $keyColumn)),
        );
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
