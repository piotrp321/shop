<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * The copy of the discount code of an order: its text, kind (`percent` or `amount`), value (the percent, or the amount
 * in grosze) and the discount it gave the order in grosze.
 */
final readonly class OrderDiscountView
{
    public function __construct(
        public string $code,
        public string $kind,
        public int $value,
        public int $amountGross,
    ) {
    }
}
