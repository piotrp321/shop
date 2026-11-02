<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;

/**
 * The gross (VAT-inclusive) catalog price: 1 to 99 999 999 grosze (0.01 to 999 999.99 PLN). The upper limit also
 * keeps every computation of {@see PriceBreakdown} far from the int range.
 */
final readonly class GrossPrice
{
    public const int MIN = 1;
    public const int MAX = 99_999_999;

    private function __construct(private Money $money)
    {
    }

    /**
     * @throws GrossPriceOutOfRange
     */
    public static function of(Money $money): self
    {
        if ($money->amount() < self::MIN || $money->amount() > self::MAX) {
            throw new GrossPriceOutOfRange();
        }

        return new self($money);
    }

    /**
     * @throws GrossPriceOutOfRange
     */
    public static function pln(int $amount): self
    {
        return self::of(Money::of($amount, Currency::PLN));
    }

    public function money(): Money
    {
        return $this->money;
    }

    public function amount(): int
    {
        return $this->money->amount();
    }

    public function currency(): Currency
    {
        return $this->money->currency();
    }

    public function equals(self $other): bool
    {
        return $this->money->equals($other->money);
    }
}
