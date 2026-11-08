<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Orders in any status of their cycle for the order list of the panel, written as plain SQL that satisfies the CHECK
 * constraints of ordering_order (moments of the statuses, payment, refund), on the test's own connection (rolled
 * back). No history, no reservation: the list reads neither.
 */
final class BackofficeOrderFixture
{
    /**
     * Removes every order inside the transaction of the test, so a list sees only the orders of the test.
     */
    public static function noOrders(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM ordering_order_notification');
        $connection->executeStatement('DELETE FROM ordering_order_status_change');
        $connection->executeStatement('DELETE FROM ordering_order_line');
        $connection->executeStatement('DELETE FROM ordering_order');
    }

    /**
     * An order of a guest, or of the customer $customerId, of one line per quantity (1 000 grosze gross a unit). `cancelled_by_staff` and `cancelled` (for
     * want of a payment) are the two cancellations. A refund exists exactly for `returned` and `cancelled_by_staff`;
     * it is due at $refundDueAt (placement + 14 days by default) and completed at $refundCompletedAt.
     *
     * @param 'new'|'paid'|'shipped'|'delivered'|'cancelled'|'cancelled_by_staff'|'returned' $status
     * @param list<int>                                                                      $quantities
     *
     * @return string the order id
     */
    public static function order(
        Connection $connection,
        string $status,
        string $placedAt,
        string $number,
        string $email = 'jan@example.com',
        array $quantities = [1],
        ?string $refundDueAt = null,
        ?string $refundCompletedAt = null,
        ?string $orderId = null,
        ?string $customerId = null,
    ): string {
        $orderId ??= Uuid::v7()->toRfc4122();
        $placed = new \DateTimeImmutable($placedAt);
        $at = static fn (string $offset): string => $placed->modify($offset)->format('Y-m-d H:i:sP');
        $total = 1_000 * array_sum($quantities);
        $paid = 'new' !== $status && 'cancelled' !== $status;
        $hasRefund = 'returned' === $status || 'cancelled_by_staff' === $status;
        $completed = $hasRefund && null !== $refundCompletedAt;

        $connection->insert('ordering_order', [
            'id' => $orderId,
            'number' => $number,
            'status' => 'cancelled_by_staff' === $status ? 'cancelled' : $status,
            'owner_type' => null === $customerId ? 'guest' : 'customer',
            'customer_id' => $customerId,
            'access_token' => null === $customerId ? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=') : null,
            'email' => $email,
            'full_name' => 'Jan Kowalski',
            'phone' => '+48600100200',
            'street' => 'Długa 1',
            'postal_code' => '00-950',
            'city' => 'Warszawa',
            'country' => 'PL',
            'currency' => 'PLN',
            'total_net' => $total,
            'total_vat' => 0,
            'total_gross' => $total,
            'idempotency_key' => 'fixture-'.$orderId,
            'request_fingerprint' => str_repeat('a', 64),
            'placed_at' => $placed->format('Y-m-d H:i:sP'),
            'payment_deadline' => $at('+30 minutes'),
            'paid_at' => $paid ? $at('+5 minutes') : null,
            'payment_id' => $paid ? Uuid::v7()->toRfc4122() : null,
            'shipped_at' => \in_array($status, ['shipped', 'delivered', 'returned'], true) ? $at('+1 day') : null,
            'delivered_at' => 'delivered' === $status ? $at('+2 days') : null,
            'returned_at' => 'returned' === $status ? $at('+3 days') : null,
            'cancelled_at' => match ($status) {
                'cancelled' => $at('+35 minutes'),
                'cancelled_by_staff' => $at('+1 hour'),
                default => null,
            },
            'cancellation_reason' => match ($status) {
                'cancelled' => 'payment_timeout',
                'cancelled_by_staff' => 'staff',
                default => null,
            },
            'refund_amount' => $hasRefund ? $total : null,
            'refund_due_at' => $hasRefund ? ($refundDueAt ?? $at('+14 days')) : null,
            'refund_completed_at' => $completed ? $refundCompletedAt : null,
            'refund_completed_by_staff_id' => $completed ? 'kc-staff-2' : null,
            'refund_completed_by_username' => $completed ? 'bartek' : null,
            'version' => 1,
        ]);
        foreach ($quantities as $position => $quantity) {
            $connection->insert('ordering_order_line', [
                'id' => Uuid::v7()->toRfc4122(),
                'order_id' => $orderId,
                'position' => $position,
                'variant_id' => Uuid::v7()->toRfc4122(),
                'product_id' => Uuid::v7()->toRfc4122(),
                'product_name' => 'Kubek',
                'variant_label' => '',
                'quantity' => $quantity,
                'unit_net' => 1_000,
                'unit_vat' => 0,
                'unit_gross' => 1_000,
                'vat_rate_bp' => 0,
                'tax_class' => 'zero',
                'line_net' => 1_000 * $quantity,
                'line_vat' => 0,
                'line_gross' => 1_000 * $quantity,
            ]);
        }

        return $orderId;
    }
}
