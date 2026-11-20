<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The answer of GetOrderPayability: the payability of every order that was read, and apart from them the orders that
 * exist but could not be read, with the reason (the class and the message of the error of the domain; the messages of
 * its invariants hold no data of the order). An unknown order and an id that is no UUID are in neither.
 */
final readonly class OrderPayabilities
{
    /**
     * @param list<OrderPayability> $orders     in the order of the ids
     * @param array<string, string> $unreadable the reason by order id
     */
    public function __construct(
        public array $orders,
        public array $unreadable,
    ) {
    }
}
