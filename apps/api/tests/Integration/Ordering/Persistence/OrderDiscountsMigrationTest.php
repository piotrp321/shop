<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real migration of the discounts of orders in the transaction DAMA rolls back after each test: its own
 * down() puts the order tables back to their shape before it, an order is inserted in that shape, its up() is
 * executed and the result is read (D8: existing orders keep their amounts). Nothing is committed.
 */
final class OrderDiscountsMigrationTest extends KernelTestCase
{
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261109090000AddOrderDiscounts.php';
    private const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-000000000031';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testExistingOrdersKeepTheirAmountsAndGetNoDiscountAndNoPromotion(): void
    {
        $this->runMigration('down');
        $this->insertOrder();
        $before = $this->amounts();

        $this->runMigration('up');

        self::assertSame($before, $this->amounts(), 'nothing is computed anew');
        self::assertSame([null, null, null, null], $this->connection->fetchNumeric('SELECT discount_code, discount_kind, discount_value, discount_gross FROM ordering_order WHERE id = ?', [self::ORDER]));
        self::assertSame(
            [[0, null, null, null, null], [0, null, null, null, null]],
            $this->connection->fetchAllNumeric('SELECT discount_gross, promotion_id, promotion_name, promotion_percent_off, regular_unit_gross FROM ordering_order_line WHERE order_id = ? ORDER BY position', [self::ORDER]),
        );
    }

    public function testDownRemovesTheColumnsOfTheDiscounts(): void
    {
        $this->runMigration('down');

        self::assertSame([], $this->connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE (table_name = 'ordering_order' AND column_name LIKE 'discount%') OR (table_name = 'ordering_order_line' AND (column_name LIKE 'discount%' OR column_name LIKE 'promotion%' OR column_name = 'regular_unit_gross'))",
        ));
        $this->runMigration('up');
    }

    /**
     * @return list<list<mixed>>
     */
    private function amounts(): array
    {
        return [
            ...$this->connection->fetchAllNumeric('SELECT total_net, total_vat, total_gross FROM ordering_order WHERE id = ?', [self::ORDER]),
            ...$this->connection->fetchAllNumeric('SELECT unit_net, unit_vat, unit_gross, line_net, line_vat, line_gross FROM ordering_order_line WHERE order_id = ? ORDER BY position', [self::ORDER]),
        ];
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

    /**
     * A new guest order of two lines in the shape before the migration: 2 × 369,00 zł and 1 × 20,00 zł at 8 %.
     */
    private function insertOrder(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ordering_order (id, number, status, owner_type, access_token, email, full_name, phone, street, postal_code, city, country, currency, total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, version)
             VALUES (?, 'R-999031', 'new', 'guest', ?, 'jan@example.com', 'Jan Kowalski', '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 61852, 13948, 75800, 'migration-discounts', ?, '2026-11-05 12:00:00+00', '2026-11-05 12:30:00+00', 1)",
            [self::ORDER, str_repeat('t', 43), str_repeat('a', 64)],
        );
        $this->connection->executeStatement("INSERT INTO ordering_order_status_change (order_id, position, from_status, to_status, occurred_at, actor_type) VALUES (?, 0, NULL, 'new', '2026-11-05 12:00:00+00', 'customer')", [self::ORDER]);
        foreach ([[0, 2, 30_000, 6_900, 36_900, 2300, 'standard'], [1, 1, 1_852, 148, 2_000, 800, 'reduced']] as [$position, $quantity, $net, $vat, $gross, $rate, $class]) {
            $this->connection->executeStatement(
                'INSERT INTO ordering_order_line (id, order_id, position, variant_id, product_id, product_name, variant_label, quantity, unit_net, unit_vat, unit_gross, vat_rate_bp, tax_class, line_net, line_vat, line_gross)
                 VALUES (gen_random_uuid(), ?, ?, gen_random_uuid(), gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [self::ORDER, $position, 'Produkt', '', $quantity, $net, $vat, $gross, $rate, $class, $net * $quantity, $vat * $quantity, $gross * $quantity],
            );
        }
    }
}
