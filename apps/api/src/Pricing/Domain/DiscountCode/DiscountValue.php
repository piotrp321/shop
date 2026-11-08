<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Promotion\PercentOff;

/**
 * The discount of a code (decision D1): a percent of the order (1–90, the PercentOff of promotions) or an amount of
 * 1 to 10 000 zł.
 */
final readonly class DiscountValue
{
    public const int MIN_AMOUNT = 100;
    public const int MAX_AMOUNT = 1_000_000;

    private function __construct(
        private ?PercentOff $percentOff,
        private ?Money $amount,
    ) {
    }

    public static function percent(PercentOff $percentOff): self
    {
        return new self($percentOff, null);
    }

    /**
     * @throws InvalidDiscountCode
     */
    public static function amount(Money $amount): self
    {
        if ($amount->amount() < self::MIN_AMOUNT || $amount->amount() > self::MAX_AMOUNT) {
            throw InvalidDiscountCode::amount();
        }

        return new self(null, $amount);
    }

    /**
     * The value as a request gives it: the percent, or the amount in grosze.
     *
     * @throws InvalidDiscountCode at the value
     */
    public static function of(DiscountKind $kind, int $value): self
    {
        if (DiscountKind::Amount === $kind) {
            return self::amount(Money::of($value, Currency::PLN));
        }
        if ($value < PercentOff::MIN || $value > PercentOff::MAX) {
            throw InvalidDiscountCode::percent();
        }

        return self::percent(PercentOff::of($value));
    }

    public function kind(): DiscountKind
    {
        return null === $this->percentOff ? DiscountKind::Amount : DiscountKind::Percent;
    }

    /**
     * The percent, or the amount in grosze.
     */
    public function value(): int
    {
        return $this->percentOff?->value() ?? $this->amount?->amount() ?? throw new \LogicException('A discount is a percent or an amount.');
    }

    public function percentOff(): ?PercentOff
    {
        return $this->percentOff;
    }

    public function amountOff(): ?Money
    {
        return $this->amount;
    }

    /**
     * Q7: a percent rounded down to 0 grosze is no discount, so a percent code works from the smallest subtotal at
     * which floor(S × p / 100) ≥ 1, ceil(100 / p) grosze. An amount code has its explicit minimum instead.
     */
    public function implicitMinimumGross(): ?int
    {
        if (null === $this->percentOff) {
            return null;
        }
        $percent = $this->percentOff->value();

        return intdiv(100 + $percent - 1, $percent);
    }

    public function equals(self $other): bool
    {
        return $this->kind() === $other->kind() && $this->value() === $other->value();
    }
}
