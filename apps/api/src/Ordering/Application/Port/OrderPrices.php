<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * The one pricing of the lines of an order (Pricing's quote of a basket, decision A2), asked once for all of them:
 * the effective unit prices and promotions, the discount of the code allocated over the lines, and the net, VAT and
 * gross of each line after it.
 */
interface OrderPrices
{
    /**
     * @param non-empty-list<CartLineToOrder> $lines        at most 50, each variant once, in the order of the cart
     * @param string|null                     $discountCode trimmed, in upper case (ASCII), at most 64 characters; null
     *                                                      for none
     */
    public function quote(array $lines, ?string $discountCode): OrderQuote;
}
