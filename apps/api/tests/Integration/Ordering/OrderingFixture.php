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
    /** The id of freeDeliveryMethod(). */
    public const string FREE_DELIVERY_METHOD = '0192f0c4-7c1a-7c4e-9a6e-00000000de10';

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
     * An active delivery method of Delivery (W12 of docs/features/delivery/spec.md) with a name of its own, by default
     * free (so the totals of an order are those of its lines), last on the list (position 999).
     *
     * @return string the id of the method
     */
    public static function deliveryMethod(Connection $connection, int $priceGross = 0, ?int $freeFromGross = null, bool $active = true, ?string $trackingUrlTemplate = null, ?string $name = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            'INSERT INTO delivery_method (id, name, description, price_gross, free_from_gross, active, position, tracking_url_template, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, ?, 999, ?, now(), now(), 1)',
            [$id, $name ?? 'Kurier testowy '.$id, 'Dostawa w 2 dni', $priceGross, $freeFromGross, $active, $trackingUrlTemplate],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING],
        );

        return $id;
    }

    /**
     * The one active delivery method that costs nothing, with a fixed id, created when missing (so also by tests whose
     * rows commit, which then leave this one row; tests of the set of methods delete them first): a placement with it
     * has the totals of its goods.
     *
     * @return string the id of the method
     */
    public static function freeDeliveryMethod(Connection $connection): string
    {
        $connection->executeStatement(
            "INSERT INTO delivery_method (id, name, description, price_gross, free_from_gross, active, position, tracking_url_template, created_at, updated_at, version)
             VALUES (?, 'Kurier testowy bez kosztu', '', 0, NULL, true, 999, NULL, now(), now(), 1) ON CONFLICT (id) DO NOTHING",
            [self::FREE_DELIVERY_METHOD],
        );

        return self::FREE_DELIVERY_METHOD;
    }

    /**
     * Deletes delivery methods of deliveryMethod() on a connection that commits.
     *
     * @param list<string> $ids
     */
    public static function deleteDeliveryMethods(Connection $connection, array $ids): void
    {
        if ([] !== $ids) {
            $connection->executeStatement('DELETE FROM delivery_method WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
        }
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
     * An order of one line (10,00 zł net, 12,30 zł gross) to $email, written as plain SQL with a number and keys of its
     * own: of a guest, or of the customer $customerId; placed now or at $placedAt; new, or paid when $paid (now, or at
     * $paidAt); without a delivery method (as before the delivery), or with a method, a delivery of $deliveryGross at
     * 23 % and the totals with it. No reservation, no outbox row, nothing in the read models. The id is a new UUID v7
     * unless given.
     *
     * @return string the order id
     */
    public static function order(
        Connection $connection,
        string $email = 'jan@example.com',
        bool $paid = false,
        ?string $paidAt = null,
        ?string $id = null,
        ?string $customerId = null,
        string $fullName = 'Jan Kowalski',
        ?string $placedAt = null,
        string $street = 'Długa 1',
        string $phone = '+48600100200',
        int $deliveryGross = 0,
    ): string {
        $orderId = $id ?? Uuid::v7()->toRfc4122();
        $placed = null === $placedAt ? null : new \DateTimeImmutable($placedAt)->format('Y-m-d H:i:sP');
        $connection->executeStatement(
            "INSERT INTO ordering_order (id, number, status, owner_type, customer_id, access_token, email, full_name, phone, street, postal_code, city, country, currency, total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, paid_at, payment_id, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '00-950', 'Warszawa', 'PL', 'PLN', 1000, 230, 1230, ?, ?, COALESCE(CAST(? AS timestamptz), now()), COALESCE(CAST(? AS timestamptz), now()) + interval '30 minutes', ?, ?, 1)",
            [
                $orderId,
                'R-'.random_int(100_000_000, 999_999_999),
                $paid ? 'paid' : 'new',
                null === $customerId ? 'guest' : 'customer',
                $customerId,
                null === $customerId ? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=') : null,
                $email,
                $fullName,
                $phone,
                $street,
                'fixture-'.$orderId,
                str_repeat('a', 64),
                $placed,
                $placed,
                $paid ? new \DateTimeImmutable($paidAt ?? 'now')->format('Y-m-d H:i:sP') : null,
                $paid ? Uuid::v7()->toRfc4122() : null,
            ],
        );
        $connection->executeStatement(
            "INSERT INTO ordering_order_line (id, order_id, position, variant_id, product_id, product_name, variant_label, quantity, unit_net, unit_vat, unit_gross, vat_rate_bp, tax_class, line_net, line_vat, line_gross)
             VALUES (?, ?, 0, ?, ?, 'Kubek', 'Standard', 1, 1000, 230, 1230, 2300, 'standard', 1000, 230, 1230)",
            [Uuid::v7()->toRfc4122(), $orderId, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()],
        );
        if ($deliveryGross > 0) {
            // Net of the gross at 23 %, half up, like Pricing.
            $net = intdiv($deliveryGross * 10_000 + 6_150, 12_300);
            $vat = $deliveryGross - $net;
            $connection->executeStatement(
                "UPDATE ordering_order SET delivery_method_id = gen_random_uuid(), delivery_method_name = 'Kurier testowy', delivery_gross = ?, delivery_net = ?, delivery_vat = ?, total_net = total_net + ?, total_vat = total_vat + ?, total_gross = total_gross + ? WHERE id = ?",
                [$deliveryGross, $net, $vat, $net, $vat, $deliveryGross, $orderId],
            );
            $connection->executeStatement('INSERT INTO ordering_order_delivery_vat (order_id, vat_rate_bp, gross, net, vat) VALUES (?, 2300, ?, ?, ?)', [$orderId, $deliveryGross, $net, $vat]);
        }

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
     * Deletes the orders (their notifications, history and lines first), the reservations they hold and the summaries of
     * their customers that are left without orders (PlaceOrder writes them), on a connection that commits.
     *
     * @param list<string> $orderIds
     */
    public static function deleteOrders(Connection $connection, array $orderIds): void
    {
        if ([] === $orderIds) {
            return;
        }
        $customers = $connection->fetchFirstColumn("SELECT DISTINCT customer_id FROM ordering_order WHERE id IN (?) AND owner_type = 'customer'", [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order_notification WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order_status_change WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order_line WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order_delivery_vat WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM ordering_order WHERE id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        if ([] !== $customers) {
            $connection->executeStatement(
                'DELETE FROM ordering_customer c WHERE c.customer_id IN (?) AND NOT EXISTS (SELECT 1 FROM ordering_order o WHERE o.customer_id = c.customer_id)',
                [$customers],
                [ArrayParameterType::STRING],
            );
        }
        StockFixture::delete($connection, [], $orderIds);
    }
}
