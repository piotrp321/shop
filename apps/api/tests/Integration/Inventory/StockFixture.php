<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Stored stocks for the tests, written on the given connection (the test's own, rolled back, or an independent one
 * that commits). Receiving stock is not part of the module yet, so a stock is written as a receipt would leave it:
 * the row and one receipt movement, which keeps physical = the sum of the physical deltas of the history.
 */
final class StockFixture
{
    /**
     * @return string the product id
     */
    public static function stock(Connection $connection, int $physical, int $reserved = 0, int $version = 1, ?string $productId = null): string
    {
        $productId ??= (string) Uuid::v7();
        $connection->executeStatement(
            'INSERT INTO inventory_stock_item (product_id, physical, reserved, version, updated_at) VALUES (?, ?, ?, ?, now())',
            [$productId, $physical, $reserved, $version],
        );
        $connection->executeStatement(
            "INSERT INTO inventory_stock_movement (id, product_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reference, source, occurred_at) VALUES (?, ?, 'receipt', ?, 0, ?, 0, ?, 'seed', now())",
            [(string) Uuid::v7(), $productId, $physical, $physical, 'fixture:'.Uuid::v7()->toRfc4122()],
        );

        return $productId;
    }

    /**
     * Deletes everything Inventory stored for the products and the reservations, on a connection that commits.
     *
     * @param list<string> $productIds
     * @param list<string> $reservationKeys
     */
    public static function delete(Connection $connection, array $productIds, array $reservationKeys): void
    {
        if ([] !== $reservationKeys) {
            $connection->executeStatement('DELETE FROM inventory_reservation_line WHERE reservation_key IN (?)', [$reservationKeys], [ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE reservation_key IN (?)', [$reservationKeys], [ArrayParameterType::STRING]);
        }
        if ([] !== $productIds) {
            $connection->executeStatement('DELETE FROM inventory_stock_movement WHERE product_id IN (?)', [$productIds], [ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM inventory_stock_item WHERE product_id IN (?)', [$productIds], [ArrayParameterType::STRING]);
        }
    }
}
