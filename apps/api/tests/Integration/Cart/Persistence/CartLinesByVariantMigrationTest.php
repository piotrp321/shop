<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration that keys cart lines by variant on the real PostgreSQL tables, in the transaction DAMA rolls
 * back after each test: the migration's own down() first puts the table back to its shape before it (PostgreSQL DDL
 * is transactional), carts and lines are inserted with product ids, the migration's up() is executed and the result
 * is read. Nothing is committed.
 *
 * The Catalog migration gave every product a default variant with the id of the product, so a line keyed by the old
 * product id is, after this migration, the line of that default variant. Here the variant id is the product id on
 * purpose: that is the data the migration meets.
 */
final class CartLinesByVariantMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261105120000KeyCartLinesByVariant.php';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const string TENT = '11111111-1111-4111-8111-111111111111';
    private const string KAYAK = '22222222-2222-4222-8222-222222222222';
    private const string GUEST_CART = '33333333-3333-4333-8333-333333333333';
    private const string CUSTOMER_CART = '44444444-4444-4444-8444-444444444444';
    private const string LINE_1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string LINE_2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';
    private const string LINE_3 = '0192f0c4-7c1a-7c4e-9a6e-000000000003';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testEveryLineKeepsItsIdAndPointsToTheDefaultVariantOfItsProduct(): void
    {
        $this->runMigration('down');
        $this->insertCatalogProductWithDefaultVariant(self::TENT, 'namiot');
        $this->insertCatalogProductWithDefaultVariant(self::KAYAK, 'kajak');
        $this->insertCart(self::GUEST_CART, null);
        $this->insertCart(self::CUSTOMER_CART, 'kc-sub-1');
        $this->insertLine(self::LINE_1, self::GUEST_CART, self::TENT, 2, '2026-10-01 12:00:00+00');
        $this->insertLine(self::LINE_2, self::GUEST_CART, self::KAYAK, 1, '2026-10-01 12:05:00+00');
        $this->insertLine(self::LINE_3, self::CUSTOMER_CART, self::TENT, 5, '2026-10-02 08:00:00+00');

        $this->runMigration('up');

        self::assertSame(
            [
                ['id' => self::LINE_1, 'cart_id' => self::GUEST_CART, 'variant_id' => self::TENT, 'product_id' => self::TENT, 'quantity' => 2],
                ['id' => self::LINE_2, 'cart_id' => self::GUEST_CART, 'variant_id' => self::KAYAK, 'product_id' => self::KAYAK, 'quantity' => 1],
                ['id' => self::LINE_3, 'cart_id' => self::CUSTOMER_CART, 'variant_id' => self::TENT, 'product_id' => self::TENT, 'quantity' => 5],
            ],
            $this->connection->fetchAllAssociative(
                "SELECT l.id, l.cart_id, l.variant_id, v.product_id, l.quantity FROM cart_line l JOIN catalog_product_variant v ON v.id = l.variant_id AND v.option_values = '[]'::jsonb ORDER BY l.id",
            ),
        );
    }

    public function testNoValueOfAnyColumnChanges(): void
    {
        $this->runMigration('down');
        $this->insertCart(self::GUEST_CART, null);
        $this->insertLine(self::LINE_1, self::GUEST_CART, self::TENT, 2, '2026-10-01 12:00:00+00');
        $this->insertLine(self::LINE_2, self::GUEST_CART, self::KAYAK, 99, '2026-10-01 12:05:00+00');
        $lines = $this->rows('product_id');
        $carts = $this->connection->fetchFirstColumn('SELECT to_jsonb(c)::text FROM cart_cart c ORDER BY id');

        $this->runMigration('up');

        self::assertSame($lines, $this->rows('variant_id'));
        self::assertSame($carts, $this->connection->fetchFirstColumn('SELECT to_jsonb(c)::text FROM cart_cart c ORDER BY id'));
        self::assertFalse($this->hasColumn('product_id'));
    }

    /**
     * A variant stays once per cart: a second line of a migrated variant in the same cart is refused, in another cart
     * it is not.
     */
    public function testAVariantStaysUniquePerCart(): void
    {
        $this->runMigration('down');
        $this->insertCart(self::GUEST_CART, null);
        $this->insertCart(self::CUSTOMER_CART, 'kc-sub-1');
        $this->insertLine(self::LINE_1, self::GUEST_CART, self::TENT, 2, '2026-10-01 12:00:00+00');

        $this->runMigration('up');

        $this->connection->executeStatement("INSERT INTO cart_line (id, cart_id, variant_id, quantity, added_at) VALUES (?, ?, ?, 1, '2026-10-03 12:00:00+00')", [self::LINE_3, self::CUSTOMER_CART, self::TENT]);
        $this->expectExceptionMessageMatches('/cart_line_cart_variant_key/');
        $this->connection->executeStatement("INSERT INTO cart_line (id, cart_id, variant_id, quantity, added_at) VALUES (?, ?, ?, 1, '2026-10-03 12:00:00+00')", [self::LINE_2, self::GUEST_CART, self::TENT]);
    }

    public function testTheUniqueIndexServesTheVariant(): void
    {
        self::assertSame(
            'CREATE UNIQUE INDEX cart_line_cart_variant_key ON public.cart_line USING btree (cart_id, variant_id)',
            $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'cart_line' AND indexname = 'cart_line_cart_variant_key'"),
        );
    }

    public function testDownPutsTheProductKeyBack(): void
    {
        $this->insertCart(self::GUEST_CART, null);
        $this->connection->executeStatement("INSERT INTO cart_line (id, cart_id, variant_id, quantity, added_at) VALUES (?, ?, ?, 1, '2026-10-01 12:00:00+00')", [self::LINE_1, self::GUEST_CART, self::TENT]);

        $this->runMigration('down');

        self::assertSame([self::TENT], $this->connection->fetchFirstColumn('SELECT product_id FROM cart_line'));
        self::assertSame(
            'CREATE UNIQUE INDEX cart_line_cart_product_key ON public.cart_line USING btree (cart_id, product_id)',
            $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'cart_line' AND indexname = 'cart_line_cart_product_key'"),
        );
    }

    private function insertCatalogProductWithDefaultVariant(string $id, string $slug): void
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM catalog_category WHERE id = ?', [self::CATEGORY_ID])) {
            $this->connection->executeStatement('INSERT INTO catalog_category (id, name, path) VALUES (?, ?, ?)', [self::CATEGORY_ID, 'Category', '/'.self::CATEGORY_ID.'/']);
        }
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, version, variant_axes)
             VALUES (?, ?, ?, '', ?, 'standard', '[]'::jsonb, 1, '[]'::jsonb)",
            [$id, $slug, 'Name '.$slug, self::CATEGORY_ID],
        );
        // As the Catalog migration left it: the default variant has the id of its product.
        $this->connection->executeStatement("INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, 0, '[]'::jsonb)", [$id, $id]);
    }

    private function insertCart(string $id, ?string $customerId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO cart_cart (id, owner_type, customer_id, status, created_at, updated_at, expires_at) VALUES (?, ?, ?, 'open', '2026-10-01 12:00:00+00', '2026-10-02 12:00:00+00', '2027-01-01 12:00:00+00')",
            [$id, null === $customerId ? 'guest' : 'customer', $customerId],
        );
    }

    private function insertLine(string $id, string $cartId, string $productId, int $quantity, string $addedAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cart_line (id, cart_id, product_id, quantity, added_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $cartId, $productId, $quantity, $addedAt],
        );
    }

    /**
     * @return list<string> every column of every line as JSON, the key column under the name `key`
     */
    private function rows(string $keyColumn): array
    {
        return array_map(
            static fn (mixed $row): string => \is_string($row) ? $row : '',
            $this->connection->fetchFirstColumn(\sprintf("SELECT ((to_jsonb(t) - '%1\$s') || jsonb_build_object('key', t.%1\$s))::text FROM cart_line t ORDER BY 1", $keyColumn)),
        );
    }

    private function hasColumn(string $name): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'cart_line' AND column_name = ?",
            [$name],
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
