<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\OrderId;

/**
 * The customers of the panel (spec ADMIN-FEATURES, section 5): an account of the store (the `sub` of its token) with
 * at least one order, with the contact of its newest order, the number of its orders and of the paid ones, the gross
 * value of the paid ones and the moments of its first and last order. A read model of the orders, never the orders
 * summed in a request. Guests are no customers.
 */
interface CustomerSummaries
{
    /**
     * Adds a new order of the customer (never a replay of one), in the transaction of the caller (PlaceOrder): the
     * contact becomes the customer's when the order is not older than the customer's last one (by the moment of
     * placement, then by the order id, the order the rebuild takes the newest order in).
     */
    public function addOrder(string $customerId, OrderId $orderId, Contact $contact, \DateTimeImmutable $placedAt): void;

    /**
     * Adds the payment of an order of the customer, in the transaction of the caller (MarkOrderPaid), only when the
     * payment pays the order.
     *
     * @param int $gross the gross total of the order, in grosze
     */
    public function addPayment(string $customerId, OrderId $orderId, int $gross): void;
}
