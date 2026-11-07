<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Rows of the modules an order is placed from, written as plain SQL on the given connection (the test's own, rolled
 * back, or one that commits): a variant that can be bought (Catalog product, current price, stock), and the rows an
 * order leaves (order, lines, reservation).
 */
final class OrderingFixture
{
    /**
     * The default variant of a new Catalog product with a current price (gross = net + 23 % VAT) and a stock.
     *
     * @return array{string, string} the variant id and the product id
     */
    public static function variant(Connection $connection, int $net, int $stock, int $reserved = 0): array
    {
        $productId = CatalogFixture::product($connection);
        $variantId = CatalogFixture::defaultVariant($connection, $productId);
        $vat = intdiv($net * 23 + 50, 100);
        $connection->executeStatement(
            "INSERT INTO pricing_variant_price (variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at) VALUES (?, 'PLN', ?, ?, ?, 2300, 'standard', 1, now())",
            [$variantId, $net + $vat, $net, $vat],
        );
        StockFixture::stock($connection, $stock, $reserved, variantId: $variantId);

        return [$variantId, $productId];
    }

    /**
     * @return array{int, int} physical and reserved units of the variant
     */
    public static function stock(Connection $connection, string $variantId): array
    {
        $row = $connection->fetchNumeric('SELECT physical, reserved FROM inventory_stock_item WHERE variant_id = ?', [$variantId]);
        \assert(\is_array($row) && \is_int($row[0]) && \is_int($row[1]));

        return [$row[0], $row[1]];
    }

    /**
     * @return array<string, int> quantity by variant of the reservation with the key, empty without one
     */
    public static function reservation(Connection $connection, string $key): array
    {
        $rows = $connection->fetchAllKeyValue('SELECT variant_id, quantity FROM inventory_reservation_line WHERE reservation_key = ? ORDER BY variant_id', [$key]);
        $lines = [];
        foreach ($rows as $variantId => $quantity) {
            \assert(\is_int($quantity));
            $lines[(string) $variantId] = $quantity;
        }

        return $lines;
    }

    /**
     * @return array<string, int> quantity by variant of the lines of the order
     */
    public static function orderLines(Connection $connection, string $orderId): array
    {
        $rows = $connection->fetchAllKeyValue('SELECT variant_id, quantity FROM ordering_order_line WHERE order_id = ? ORDER BY variant_id', [$orderId]);
        $lines = [];
        foreach ($rows as $variantId => $quantity) {
            \assert(\is_int($quantity));
            $lines[(string) $variantId] = $quantity;
        }

        return $lines;
    }

    /**
     * @param list<mixed> $params
     */
    public static function count(Connection $connection, string $sql, array $params = []): int
    {
        $count = $connection->fetchOne($sql, $params);
        \assert(\is_int($count));

        return $count;
    }

    /**
     * A guest order of one line (10,00 zł net, 12,30 zł gross) to $email, written as plain SQL with a number and keys
     * of its own: new, or paid when $paid. No reservation, no outbox row.
     *
     * @return string the order id
     */
    public static function order(Connection $connection, string $email = 'jan@example.com', bool $paid = false): string
    {
        $orderId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            "INSERT INTO ordering_order (id, number, status, owner_type, customer_id, access_token, email, full_name, phone, street, postal_code, city, country, currency, total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, paid_at, payment_id, version)
             VALUES (?, ?, ?, 'guest', NULL, ?, ?, 'Jan Kowalski', '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 230, 1230, ?, ?, now(), now() + interval '30 minutes', ?, ?, 1)",
            [
                $orderId,
                'R-'.random_int(100_000_000, 999_999_999),
                $paid ? 'paid' : 'new',
                rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
                $email,
                'fixture-'.$orderId,
                str_repeat('a', 64),
                $paid ? new \DateTimeImmutable()->format('Y-m-d H:i:sP') : null,
                $paid ? Uuid::v7()->toRfc4122() : null,
            ],
        );
        $connection->executeStatement(
            "INSERT INTO ordering_order_line (id, order_id, position, variant_id, product_id, product_name, variant_label, quantity, unit_net, unit_vat, unit_gross, vat_rate_bp, tax_class, line_net, line_vat, line_gross)
             VALUES (?, ?, 0, ?, ?, 'Kubek', 'Standard', 1, 1000, 230, 1230, 2300, 'standard', 1000, 230, 1230)",
            [Uuid::v7()->toRfc4122(), $orderId, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()],
        );

        return $orderId;
    }

    /**
     * The moment the email of the kind (`confirmation`, `payment_received`, …) of the order went out, null when it
     * has not.
     */
    public static function notificationSentAt(Connection $connection, string $orderId, string $kind): ?\DateTimeImmutable
    {
        $value = $connection->fetchOne('SELECT sent_at FROM ordering_order_notification WHERE order_id = ? AND kind = ?', [$orderId, $kind]);
        \assert(false === $value || null === $value || \is_string($value));

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /**
     * Deletes the orders (their notifications, history and lines first) and the reservations they hold, on a connection that
     * commits.
     *
     * @param list<string> $orderIds
     */
    public static function deleteOrders(Connection $connection, array $orderIds): void
    {
        if ([] === $orderIds) {
            return;
        }
        $connection->executeStatement('DELETE FROM ordering_order_notification WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order_status_change WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order_line WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order WHERE id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        StockFixture::delete($connection, [], $orderIds);
    }
}
