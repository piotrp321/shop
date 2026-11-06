<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What a payment pays: a positive number of minor units (grosze) of a currency, never a float.
 */
final readonly class Amount
{
    private function __construct(
        private int $minorUnits,
        private Currency $currency,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(int $minorUnits, Currency $currency): self
    {
        if ($minorUnits < 1) {
            throw new \InvalidArgumentException('An amount to pay is more than zero.');
        }

        return new self($minorUnits, $currency);
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * Currency has one case, so both amounts are in it; a second currency makes this compare it too.
     */
    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }
}
