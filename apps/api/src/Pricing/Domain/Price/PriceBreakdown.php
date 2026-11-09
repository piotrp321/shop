<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Tax\VatRate;

/**
 * Gross, net and VAT of a price at a VAT rate. {@see netOf()} is the only place where net and VAT are computed: for a
 * price ({@see fromGross()}) and for the value of a line of a basket ({@see ofLineGross()}).
 */
final readonly class PriceBreakdown
{
    /**
     * The largest value of a line: 99 units at GrossPrice::MAX. 2 · gross · 10000 + d stays below 2^48.
     */
    public const int MAX_LINE_GROSS = 99 * GrossPrice::MAX;

    private function __construct(
        private GrossPrice $gross,
        private Money $net,
        private Money $vat,
        private VatRate $rate,
    ) {
    }

    /**
     * Net and VAT of a price by {@see netOf()}. The gross limit keeps 2 · gross · 10000 + d below 2^41.
     */
    public static function fromGross(GrossPrice $gross, VatRate $rate): self
    {
        $net = self::netOf($gross->amount(), $rate);

        return new self(
            $gross,
            Money::of($net, $gross->currency()),
            Money::of($gross->amount() - $net, $gross->currency()),
            $rate,
        );
    }

    /**
     * Net and VAT of the gross value of a line (the units × the unit gross, less the discount of a code), by the
     * formula of {@see fromGross()}: from 0 grosze (a line discounted to nothing) up to {@see MAX_LINE_GROSS}, so it is
     * not a GrossPrice.
     *
     * @throws \InvalidArgumentException when the gross is negative or above MAX_LINE_GROSS
     */
    public static function ofLineGross(Money $gross, VatRate $rate): LineBreakdown
    {
        if ($gross->amount() < 0 || $gross->amount() > self::MAX_LINE_GROSS) {
            throw new \InvalidArgumentException('The gross of a line must be between 0 and 99 units at the largest price.');
        }
        $net = self::netOf($gross->amount(), $rate);

        return new LineBreakdown($gross, Money::of($net, $gross->currency()), Money::of($gross->amount() - $net, $gross->currency()), $rate);
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

    /**
     * Net = gross / (1 + rate) rounded HALF_UP to a grosz, in integers only: with d = 10000 + rate in basis points,
     * net = intdiv(2 · gross · 10000 + d, 2 · d). VAT is the gross less the net, so net + VAT is always the gross.
     */
    private static function netOf(int $gross, VatRate $rate): int
    {
        $divisor = 10000 + $rate->basisPoints();

        return intdiv(2 * $gross * 10000 + $divisor, 2 * $divisor);
    }

    public function equals(self $other): bool
    {
        return $this->gross->equals($other->gross)
            && $this->net->equals($other->net)
            && $this->vat->equals($other->vat)
            && $this->rate->equals($other->rate);
    }
}
