<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The unit price of a variant copied from Pricing when the order is placed: net, VAT and gross (net + vat = gross),
 * the VAT rate in basis points and the tax class. The order keeps the copy, never a reference.
 */
final readonly class PriceCopy
{
    public const int MAX_TAX_CLASS_LENGTH = 50;

    private function __construct(
        private Money $net,
        private Money $vat,
        private Money $gross,
        private int $vatRateBp,
        private string $taxClass,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(int $net, int $vat, int $gross, int $vatRateBp, string $taxClass, Currency $currency): self
    {
        if ($net + $vat !== $gross) {
            throw new \InvalidArgumentException('A price has net + vat = gross.');
        }
        if ($vatRateBp < 0) {
            throw new \InvalidArgumentException('A VAT rate is never negative.');
        }
        $length = mb_strlen($taxClass);
        if ($length < 1 || $length > self::MAX_TAX_CLASS_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A tax class has 1 to %d characters.', self::MAX_TAX_CLASS_LENGTH));
        }

        return new self(Money::of($net, $currency), Money::of($vat, $currency), Money::of($gross, $currency), $vatRateBp, $taxClass);
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function vat(): Money
    {
        return $this->vat;
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function vatRateBp(): int
    {
        return $this->vatRateBp;
    }

    public function taxClass(): string
    {
        return $this->taxClass;
    }

    public function currency(): Currency
    {
        return $this->gross->currency();
    }
}
