<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * A code the quote did not apply: why (`invalid`, `used_up`, `below_minimum`), the Polish detail of Pricing (the same
 * as the 409 of an order) and, for `below_minimum`, the smallest subtotal the code works from.
 */
final readonly class RejectedCartDiscount
{
    public string $status;

    public function __construct(
        public string $code,
        public string $reason,
        public string $detail,
        public ?int $minimumOrderGross,
    ) {
        $this->status = 'rejected';
    }
}
