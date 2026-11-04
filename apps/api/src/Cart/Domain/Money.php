<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * A non-negative amount in grosze (the minor unit) and its currency; never a float. The largest cart total
 * (50 lines × 99 units × 99 999 999 grosze) fits an int.
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
        if ($amount < 0) {
            throw new \InvalidArgumentException('An amount of a cart is never negative.');
        }

        return new self($amount, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * Currency has one case (PLN), so both amounts are always in the same currency.
     */
    public function add(self $other): self
    {
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return self::of($this->amount * $factor, $this->currency);
    }
}
