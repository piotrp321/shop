<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * Removes all orders, their lines, their notifications and their scheduled ExpireUnpaidOrder messages. Only for the
 * demo data (`ordering:seed-demo`, run by `make seed` before `inventory:seed-demo` removes the reservations) and
 * tests: orders are otherwise kept for ever. The number sequence stays where it is.
 */
interface OrderingReset
{
    public function reset(): void;
}
