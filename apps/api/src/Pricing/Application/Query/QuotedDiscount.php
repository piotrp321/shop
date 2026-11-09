<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The code a quote applied: its text, kind (`percent` or `amount`), value (the percent, or the amount in grosze) and
 * the discount it gave in grosze.
 */
final readonly class QuotedDiscount
{
    public function __construct(
        public string $codeId,
        public string $code,
        public string $kind,
        public int $value,
        public int $amountGross,
    ) {
    }
}
