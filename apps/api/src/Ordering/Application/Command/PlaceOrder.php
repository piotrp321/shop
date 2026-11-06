<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\CartReference;

/**
 * Places an order of the whole cart. The request carries no lines, quantities or prices: they come from the locked
 * cart, Pricing and the domain. The expected gross total (what the customer saw) is only compared with the computed
 * one, never stored.
 */
final readonly class PlaceOrder
{
    /**
     * @param string $email          from the request of a guest, from the token of a customer
     * @param string $idempotencyKey 1 to 100 characters [A-Za-z0-9._:-]
     */
    public function __construct(
        public CartReference $cart,
        public string $email,
        public string $fullName,
        public string $phone,
        public string $street,
        public string $postalCode,
        public string $city,
        public int $expectedTotalGross,
        public string $idempotencyKey,
    ) {
    }
}
