<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Tax\VatRate;

/**
 * Gross, net and VAT of a price at a VAT rate. {@see fromGross()} is the only place where net and VAT are computed.
 */
final readonly class PriceBreakdown
{
    private function __construct(
        private GrossPrice $gross,
        private Money $net,
        private Money $vat,
        private VatRate $rate,
    ) {
    }

    /**
     * Net = gross / (1 + rate) rounded HALF_UP to a grosz, in integers only: with d = 10000 + rate in basis points,
     * net = intdiv(2 · gross · 10000 + d, 2 · d). VAT = gross − net, so net + VAT is always the gross. The gross
     * limit keeps 2 · gross · 10000 + d below 2^41.
     */
    public static function fromGross(GrossPrice $gross, VatRate $rate): self
    {
        $divisor = 10000 + $rate->basisPoints();
        $net = intdiv(2 * $gross->amount() * 10000 + $divisor, 2 * $divisor);

        return new self(
            $gross,
            Money::of($net, $gross->currency()),
            Money::of($gross->amount() - $net, $gross->currency()),
            $rate,
        );
    }

    /**
     * A breakdown as it was stored, net and VAT in the currency of the gross. Nothing is computed; the parts only
     * have to add up.
     *
     * @throws \InvalidArgumentException when net or VAT is negative or they do not add up to the gross
     */
    public static function restore(GrossPrice $gross, int $net, int $vat, VatRate $rate): self
    {
        if ($net < 0 || $vat < 0 || $net + $vat !== $gross->amount()) {
            throw new \InvalidArgumentException('Net and VAT must not be negative and must add up to the gross price.');
        }

        return new self($gross, Money::of($net, $gross->currency()), Money::of($vat, $gross->currency()), $rate);
    }

    public function gross(): GrossPrice
    {
        return $this->gross;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function vat(): Money
    {
        return $this->vat;
    }

    public function rate(): VatRate
    {
        return $this->rate;
    }

    public function currency(): Currency
    {
        return $this->gross->currency();
    }

    public function equals(self $other): bool
    {
        return $this->gross->equals($other->gross)
            && $this->net->equals($other->net)
            && $this->vat->equals($other->vat)
            && $this->rate->equals($other->rate);
    }
}
