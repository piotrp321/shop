<?php

declare(strict_types=1);

namespace App\Payment\Application;

/**
 * What Ordering answered about the orders of a batch: the payability of every order it read, and apart from them the
 * orders that exist but could not be read, with the reason. An order Ordering does not know is in neither.
 */
final readonly class OrderPayabilities
{
    /**
     * @param array<string, OrderPayability> $orders     by order id
     * @param array<string, string>          $unreadable the reason by order id
     */
    public function __construct(
        public array $orders,
        public array $unreadable,
    ) {
    }
}
