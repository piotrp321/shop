<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

use App\Cart\Domain\Money;

/**
 * A gross amount in grosze and its currency.
 */
final readonly class GrossAmount
{
    public function __construct(
        public int $gross,
        public string $currency,
    ) {
    }

    public static function of(Money $money): self
    {
        return new self($money->amount(), $money->currency()->value);
    }
}
