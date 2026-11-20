<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderDeliveryShare;

/**
 * The one pricing of the lines of an order (Pricing's quote of a basket, decision A2), asked once for all of them:
 * the effective unit prices and promotions, the discount of the code allocated over the lines, and the net, VAT and
 * gross of each line after it. And the split of the cost of a delivery over the VAT rates of the goods of the order
 * (Pricing's SplitDeliveryCost, decision A3 of docs/features/delivery/spec.md).
 */
interface OrderPrices
{
    /**
     * @param non-empty-list<CartLineToOrder> $lines        at most 50, each variant once, in the order of the cart
     * @param string|null                     $discountCode trimmed, in upper case (ASCII), at most 64 characters; null
     *                                                      for none
     */
    public function quote(array $lines, ?string $discountCode): OrderQuote;

    /**
     * @param int             $costGross   the gross cost of the delivery in grosze, above 0
     * @param array<int, int> $goodsByRate the gross of the lines after the discount by VAT rate in basis points; they
     *                                     add up to at least 1 grosz
     *
     * @return list<OrderDeliveryShare> the shares above 0, by rate in descending order, adding up to the cost
     */
    public function deliveryShares(int $costGross, array $goodsByRate): array;
}
