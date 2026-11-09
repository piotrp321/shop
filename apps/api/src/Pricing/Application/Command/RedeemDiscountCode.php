<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

/**
 * Records the usage of a code by an order (decision D7). Dispatched only nested in the transaction of Ordering's
 * PlaceOrder, after the order id is given; a rejection rolls the whole placement back.
 */
final readonly class RedeemDiscountCode
{
    /**
     * @param string $codeId        the code the quote of the order applied
     * @param string $orderId       an id of Ordering, the key of the usage
     * @param int    $subtotalGross the gross of the effective prices of the order before the code, in grosze
     */
    public function __construct(
        public string $codeId,
        public string $orderId,
        public int $subtotalGross,
    ) {
    }
}
