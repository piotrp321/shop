<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * The delivery of an order as Delivery prices it (QuoteDelivery, decision A2 of docs/features/delivery/spec.md): the
 * cost of a method for the goods of the order, so the placement charges what the cart showed for the same data.
 */
interface OrderDeliveryQuotes
{
    /**
     * @param string $methodId   a UUID in lower case
     * @param int    $goodsGross the gross of the goods after the discounts, in grosze
     *
     * @return QuotedDeliveryMethod|null null when the method is unknown or not active
     */
    public function quote(string $methodId, int $goodsGross): ?QuotedDeliveryMethod;
}
