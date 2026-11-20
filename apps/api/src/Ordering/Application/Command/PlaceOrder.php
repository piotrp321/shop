<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\CartReference;

/**
 * Places an order of the whole cart with the chosen delivery method. The request carries no lines, quantities or
 * prices: they come from the locked cart, Pricing, Delivery and the domain. The expected gross total (what the
 * customer saw, after the discount of the code, with the delivery) is only compared with the computed one, never
 * stored.
 */
final readonly class PlaceOrder
{
    /**
     * @param string      $email            from the request of a guest, from the token of a customer
     * @param string      $deliveryMethodId a UUID in any letter case
     * @param string      $idempotencyKey   1 to 100 characters [A-Za-z0-9._:-]
     * @param string|null $discountCode     as typed; null, empty or only white space is no code; at most 64 characters
     * @param string      $clientAddress    the address of the client as the limits count it (ClientIp, from the controller;
     *                                      `unknown` without one, like ClientIp): only for the limit of unpaid orders,
     *                                      not part of the request fingerprint, so a replay from another network works
     */
    public function __construct(
        public CartReference $cart,
        public string $email,
        public string $fullName,
        public string $phone,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $deliveryMethodId,
        public int $expectedTotalGross,
        public string $idempotencyKey,
        public ?string $discountCode = null,
        public string $clientAddress = 'unknown',
    ) {
    }
}
