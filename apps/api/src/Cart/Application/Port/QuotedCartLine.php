<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\Money;

/**
 * A line as the quote priced it: the effective unit gross and the value before the code (unit gross × units).
 */
final readonly class QuotedCartLine
{
    public function __construct(
        public Money $unitGross,
        public Money $grossBeforeDiscount,
    ) {
    }
}
