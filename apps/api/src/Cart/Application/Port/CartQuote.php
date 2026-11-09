<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

/**
 * The one pricing of the purchasable lines of a cart (Pricing, decision D9): their effective unit prices, values and
 * totals, with the discount of a code or why the code gives none. The placement of an order prices them the same way.
 */
interface CartQuote
{
    /**
     * @param list<CartQuoteLine> $lines        1 to 50 lines of distinct variants, in the order of the cart
     * @param string|null         $discountCode the code as the customer typed it (blank: no code), at most 64 characters
     */
    public function quote(array $lines, ?string $discountCode): CartQuoteResult;
}
