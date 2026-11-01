<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Money;

/**
 * An amount in minor units (grosze for PLN) and its currency. Never a float.
 */
final readonly class Money
{
    private function __construct(
        private int $amount,
        private Currency $currency,
    ) {
    }

    public static function of(int $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return [$this->amount, $this->currency] === [$other->amount, $other->currency];
    }
}
