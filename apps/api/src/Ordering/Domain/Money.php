<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * A non-negative amount in grosze (int, never float) and its currency.
 */
final readonly class Money
{
    private function __construct(
        private int $amount,
        private Currency $currency,
    ) {
        if ($amount < 0) {
            throw new \InvalidArgumentException('An amount is never negative.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(int $amount, Currency $currency): self
    {
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
     * Currency has one case, so both amounts are in it; a second currency makes this the place to refuse a mix.
     */
    public function add(self $other): self
    {
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }
}
