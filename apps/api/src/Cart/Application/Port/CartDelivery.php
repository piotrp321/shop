<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

/**
 * The delivery of a cart (Delivery's QuoteDelivery, the one place the cost of a delivery is computed): the active
 * methods with their cost for the goods of this value.
 */
interface CartDelivery
{
    /**
     * @param int $goodsGross the gross value of the goods after discounts (promotions and the code), in grosze, not negative
     */
    public function quote(int $goodsGross): CartDeliveryQuote;
}
