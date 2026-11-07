<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The order of a customer for the history in the store.
 */
final readonly class GetCustomerOrder
{
    /**
     * @param string $orderId    a UUID; anything else is an unknown order
     * @param string $customerId the `sub` of the token of the customer
     */
    public function __construct(
        public string $orderId,
        public string $customerId,
    ) {
    }
}
