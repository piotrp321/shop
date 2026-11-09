<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * A code the quote applied: its text, the discount in grosze, its kind (`percent` or `amount`) and value (the percent,
 * or the amount in grosze).
 */
final readonly class AppliedCartDiscount
{
    public string $status;

    public function __construct(
        public string $code,
        public int $amountGross,
        public string $kind,
        public int $value,
    ) {
        $this->status = 'applied';
    }
}
