<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Demo;

use App\Inventory\Infrastructure\Demo\DbalInventoryReset;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * InventoryReset on shop_test (rolled back after the test).
 */
final class DbalInventoryResetTest extends KernelTestCase
{
    public function testRemovesAllStocksReservationsAndTheirHistory(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $first = StockFixture::stock($connection, 10, 3);
        StockFixture::stock($connection, 5);
        $connection->executeStatement("INSERT INTO inventory_reservation (reservation_key, status, created_at, updated_at) VALUES ('order-1', 'active', now(), now())");
        $connection->executeStatement('INSERT INTO inventory_reservation_line (reservation_key, product_id, quantity) VALUES (?, ?, 3)', ['order-1', $first]);
        foreach (['inventory_stock_item' => 2, 'inventory_stock_movement' => 2, 'inventory_reservation' => 1, 'inventory_reservation_line' => 1] as $table => $rows) {
            self::assertGreaterThanOrEqual($rows, $this->rows($connection, $table));
        }

        $reset = new DbalInventoryReset($connection);
        $reset->reset();
        $reset->reset();

        foreach (['inventory_stock_item', 'inventory_stock_movement', 'inventory_reservation', 'inventory_reservation_line'] as $table) {
            self::assertSame(0, $this->rows($connection, $table), $table);
        }
    }

    private function rows(Connection $connection, string $table): int
    {
        $rows = $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($rows);

        return $rows;
    }
}
