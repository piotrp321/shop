<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * What a payment is started for: a guest cart of one variant (1 000 net, 1 230 gross) and the command that places
 * its order; and the removal of the rows a test that commits leaves (payments, sessions of the simulator, orders).
 */
final class PaymentFixture
{
    public const int GROSS = 1_230;

    /**
     * @return array{PlaceOrder, string, string} the placement, the variant and its product
     */
    public static function placement(Connection $connection): array
    {
        [$variant, $product] = OrderingFixture::variant($connection, 1_000, 10);
        $cart = CartFixture::cart($connection, expiresAt: '+30 days', lines: [[$variant, 1]]);

        return [new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', self::GROSS, 'payment-order-'.Uuid::v7()->toRfc4122()), $variant, $product];
    }

    public static function placed(mixed $result): PlacedOrder
    {
        \assert($result instanceof PlacedOrder);

        return $result;
    }

    /**
     * Deletes the payments of the orders with their handled webhook events, the sessions of the simulator of their
     * numbers, and the orders.
     *
     * @param list<string> $orderIds
     */
    public static function deleteOrders(Connection $connection, array $orderIds): void
    {
        if ([] === $orderIds) {
            return;
        }
        $numbers = $connection->fetchFirstColumn('SELECT number FROM ordering_order WHERE id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM payment_webhook_event WHERE payment_id IN (SELECT id FROM payment_payment WHERE order_id IN (?))', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM payment_payment WHERE order_id IN (?)', [$orderIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM payment_simulator_transaction WHERE reference IN (?)', [$numbers], [ArrayParameterType::STRING]);
        OrderingFixture::deleteOrders($connection, $orderIds);
    }
}
