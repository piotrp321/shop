<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The values of a line copied from the quote of Pricing (decisions A2, D8): its share of the discount of the code and
 * its net, VAT and gross after it. The net and the VAT are computed by Pricing from the gross of the line, never as
 * the unit net times the quantity, so net + vat = gross to the grosz.
 */
final readonly class LineAmounts
{
    private function __construct(
        private Money $discount,
        private Money $net,
        private Money $vat,
        private Money $gross,
    ) {
    }

    /**
     * @throws \InvalidArgumentException a negative amount, or net + vat ≠ gross
     */
    public static function of(int $discount, int $net, int $vat, int $gross, Currency $currency): self
    {
        if ($net + $vat !== $gross) {
            throw new \InvalidArgumentException('The values of a line have net + vat = gross.');
        }

        return new self(Money::of($discount, $currency), Money::of($net, $currency), Money::of($vat, $currency), Money::of($gross, $currency));
    }

    public function discount(): Money
    {
        return $this->discount;
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
}
