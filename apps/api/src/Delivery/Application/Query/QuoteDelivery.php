<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

/**
 * The delivery of goods of this gross value after discounts (promotions and the discount code), in grosze: answered
 * with a DeliveryQuoteView. The one place the cost of a delivery is computed (A2): the cart and the placement of an
 * order ask it the same, so they cannot differ on the same data. A negative value is a logic error of the caller.
 */
final readonly class QuoteDelivery
{
    public function __construct(public int $goodsGross)
    {
    }
}
