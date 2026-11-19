<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * How large an order of a guest may be: a guest order reserves stock before it is paid, so it is smaller than the
 * order of a customer (Order::MAX_LINES, OrderLine::MAX_QUANTITY), whose limits stay as they are.
 */
final class GuestOrderSize
{
    public const int MAX_LINES = 10;
    public const int MAX_QUANTITY = 10;

    private function __construct()
    {
    }

    /**
     * @param list<int> $quantities the units of each line of the cart
     *
     * @throws GuestOrderTooLarge
     */
    public static function ensureWithin(array $quantities): void
    {
        if (\count($quantities) > self::MAX_LINES || [] !== array_filter($quantities, static fn (int $quantity): bool => $quantity > self::MAX_QUANTITY)) {
            throw new GuestOrderTooLarge();
        }
    }
}
