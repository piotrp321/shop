<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that keys stock by variant on the real PostgreSQL tables, in the transaction DAMA rolls
 * back after each test: the migration's own down() first puts the tables back to their shape before it (PostgreSQL
 * DDL is transactional), stocks, reservations and history are inserted with product ids, the migration's up() is
 * executed and the result is read. Nothing is committed.
 *
 * The Catalog migration gave every product a default variant with the id of the product, so a stock keyed by the old
 * product id is, after this migration, the stock of that default variant. Here the variant id is the product id on
 * purpose: that is the data the migration meets.
 */
final class VariantStockMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261104140000KeyStockByVariant.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const string TENT = '11111111-1111-4111-8111-111111111111';
    private const string KAYAK = '22222222-2222-4222-8222-222222222222';
    private const string MOVEMENT_1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string MOVEMENT_2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';
    private const string MOVEMENT_3 = '0192f0c4-7c1a-7c4e-9a6e-000000000003';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testEveryStockReservationLineMovementAndReceiptKeyKeepsItsIdAndPointsToTheDefaultVariantOfItsProduct(): void
    {
        $this->runMigration('down');
        $this->insertCatalogProductWithDefaultVariant(self::TENT, 'namiot');
        $this->insertCatalogProductWithDefaultVariant(self::KAYAK, 'kajak');
        $this->insertStock(self::TENT, 12, 2, 3);
        $this->insertStock(self::KAYAK, 5, 1, 2);
        $this->insertReservation('order-1', [self::TENT => 2, self::KAYAK => 1]);
        $this->insertMovement(self::MOVEMENT_1, self::TENT, 'receipt', 12, 0, 'seed:'.self::TENT);
        $this->insertMovement(self::MOVEMENT_2, self::KAYAK, 'receipt', 5, 0, 'delivery-1');
        $this->insertMovement(self::MOVEMENT_3, self::TENT, 'reservation', 0, 2, 'order-1');

        $this->runMigration('up');

        $defaultVariantJoin = 'JOIN catalog_product_variant v ON v.id = t.variant_id AND v.option_values = \'[]\'::jsonb';
        self::assertSame(
            [
                ['variant_id' => self::TENT, 'product_id' => self::TENT, 'physical' => 12, 'reserved' => 2, 'version' => 3],
                ['variant_id' => self::KAYAK, 'product_id' => self::KAYAK, 'physical' => 5, 'reserved' => 1, 'version' => 2],
            ],
            $this->connection->fetchAllAssociative("SELECT t.variant_id, v.product_id, t.physical, t.reserved, t.version FROM inventory_stock_item t {$defaultVariantJoin} ORDER BY t.variant_id"),
        );
        self::assertSame(
            [
                ['reservation_key' => 'order-1', 'variant_id' => self::TENT, 'product_id' => self::TENT, 'quantity' => 2],
                ['reservation_key' => 'order-1', 'variant_id' => self::KAYAK, 'product_id' => self::KAYAK, 'quantity' => 1],
            ],
            $this->connection->fetchAllAssociative("SELECT t.reservation_key, t.variant_id, v.product_id, t.quantity FROM inventory_reservation_line t {$defaultVariantJoin} ORDER BY t.variant_id"),
        );
        self::assertSame(
            [
                ['id' => self::MOVEMENT_1, 'variant_id' => self::TENT, 'product_id' => self::TENT, 'type' => 'receipt', 'reference' => 'seed:'.self::TENT],
                ['id' => self::MOVEMENT_2, 'variant_id' => self::KAYAK, 'product_id' => self::KAYAK, 'type' => 'receipt', 'reference' => 'delivery-1'],
                ['id' => self::MOVEMENT_3, 'variant_id' => self::TENT, 'product_id' => self::TENT, 'type' => 'reservation', 'reference' => 'order-1'],
            ],
            $this->connection->fetchAllAssociative("SELECT t.id, t.variant_id, v.product_id, t.type, t.reference FROM inventory_stock_movement t {$defaultVariantJoin} ORDER BY t.id"),
        );
    }

    public function testNoValueOfAnyColumnChanges(): void
    {
        $this->runMigration('down');
        $this->insertStock(self::TENT, 12, 2, 3);
        $this->insertReservation('order-1', [self::TENT => 2]);
        $this->insertMovement(self::MOVEMENT_1, self::TENT, 'receipt', 12, 0, 'delivery-1');
        $stock = $this->rows('inventory_stock_item', 'product_id');
        $lines = $this->rows('inventory_reservation_line', 'product_id');
        $movements = $this->rows('inventory_stock_movement', 'product_id');
        $reservations = $this->rows('inventory_reservation', 'reservation_key');

        $this->runMigration('up');

        self::assertSame($stock, $this->rows('inventory_stock_item', 'variant_id'));
        self::assertSame($lines, $this->rows('inventory_reservation_line', 'variant_id'));
        self::assertSame($movements, $this->rows('inventory_stock_movement', 'variant_id'));
        self::assertSame($reservations, $this->rows('inventory_reservation', 'reservation_key'));
        foreach (['inventory_stock_item', 'inventory_reservation_line', 'inventory_stock_movement'] as $table) {
            self::assertFalse($this->hasColumn($table, 'product_id'), $table);
        }
    }

    /**
     * The receipt key stays a key of one receipt: a second receipt with a key of a migrated one is refused.
     */
    public function testAMigratedReceiptKeyStaysTaken(): void
    {
        $this->runMigration('down');
        $this->insertMovement(self::MOVEMENT_1, self::TENT, 'receipt', 12, 0, 'delivery-1');

        $this->runMigration('up');

        $this->expectExceptionMessageMatches('/inventory_stock_movement_receipt_reference_key/');
        $this->connection->executeStatement(
            "INSERT INTO inventory_stock_movement (id, variant_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reference, source, occurred_at)
             VALUES (?, ?, 'receipt', 1, 0, 1, 0, 'delivery-1', 'staff', '2026-10-01 12:00:00+00')",
            [self::MOVEMENT_2, self::KAYAK],
        );
    }

    public function testTheKeysAndTheHistoryIndexServeTheVariant(): void
    {
        self::assertSame(
            'CREATE INDEX inventory_stock_movement_variant_occurred_at_idx ON public.inventory_stock_movement USING btree (variant_id, occurred_at DESC, id)',
            $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'inventory_stock_movement' AND indexname LIKE '%occurred_at%'"),
        );
        self::assertSame('PRIMARY KEY (variant_id)', $this->primaryKey('inventory_stock_item'));
        self::assertSame('PRIMARY KEY (reservation_key, variant_id)', $this->primaryKey('inventory_reservation_line'));
    }

    public function testDownPutsTheProductKeysBack(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at) VALUES (?, 1, 0, 1, '2026-10-01 12:00:00+00')",
            [self::TENT],
        );

        $this->runMigration('down');

        self::assertSame([self::TENT], $this->connection->fetchFirstColumn('SELECT product_id FROM inventory_stock_item'));
        self::assertTrue($this->hasColumn('inventory_reservation_line', 'product_id'));
        self::assertTrue($this->hasColumn('inventory_stock_movement', 'product_id'));
        self::assertSame(
            'CREATE INDEX inventory_stock_movement_product_occurred_at_idx ON public.inventory_stock_movement USING btree (product_id, occurred_at DESC, id)',
            $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'inventory_stock_movement' AND indexname LIKE '%occurred_at%'"),
        );
    }

    private function insertCatalogProductWithDefaultVariant(string $id, string $slug): void
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM catalog_category WHERE id = ?', [self::CATEGORY_ID])) {
            $this->connection->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [self::CATEGORY_ID, 'Category', self::CATEGORY_ID, '/'.self::CATEGORY_ID.'/']);
        }
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, version, variant_axes, status, updated_at)
             VALUES (?, ?, ?, '', ?, 'standard', '[]'::jsonb, 1, '[]'::jsonb, 'published', now())",
            [$id, $slug, 'Name '.$slug, self::CATEGORY_ID],
        );
        // As the Catalog migration left it: the default variant has the id of its product.
        $this->connection->executeStatement("INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, 0, '[]'::jsonb)", [$id, $id]);
    }

    private function insertStock(string $productId, int $physical, int $reserved, int $version): void
    {
        $this->connection->executeStatement(
            "INSERT INTO inventory_stock_item (product_id, physical, reserved, version, updated_at) VALUES (?, ?, ?, ?, '2026-10-01 12:00:00+00')",
            [$productId, $physical, $reserved, $version],
        );
    }

    /**
     * @param array<string, int> $lines product id => quantity
     */
    private function insertReservation(string $key, array $lines): void
    {
        $this->connection->executeStatement(
            "INSERT INTO inventory_reservation (reservation_key, status, created_at, updated_at) VALUES (?, 'active', '2026-10-01 12:00:00+00', '2026-10-01 12:00:00+00')",
            [$key],
        );
        foreach ($lines as $productId => $quantity) {
            $this->connection->executeStatement('INSERT INTO inventory_reservation_line (reservation_key, product_id, quantity) VALUES (?, ?, ?)', [$key, $productId, $quantity]);
        }
    }

    private function insertMovement(string $id, string $productId, string $type, int $physicalDelta, int $reservedDelta, string $reference): void
    {
        $this->connection->executeStatement(
            "INSERT INTO inventory_stock_movement (id, product_id, type, physical_delta, reserved_delta, physical_after, reserved_after, note, reference, source, actor_id, actor_name, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Dostawa', ?, 'staff', 'sub-1', 'Wanda Magazyn', '2026-10-01 12:00:00+00')",
            [$id, $productId, $type, $physicalDelta, $reservedDelta, max(0, $physicalDelta), $reservedDelta, $reference],
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

    private function primaryKey(string $table): mixed
    {
        return $this->connection->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = CAST(? AS regclass) AND contype = 'p' AND conname = ?",
            [$table, $table.'_pkey'],
        );
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
