<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The sums of the values of the lines of an order: the one place they are computed. Every line has
 * net + vat = gross, so the sums have it too, to the grosz.
 */
final readonly class OrderTotals
{
    private function __construct(
        private Money $net,
        private Money $vat,
        private Money $gross,
    ) {
    }

    /**
     * @param list<OrderLine> $lines
     *
     * @throws InvalidOrder no line
     */
    public static function of(array $lines): self
    {
        if ([] === $lines) {
            throw new InvalidOrder('An order has at least one line.');
        }
        $currency = $lines[0]->unitPrice()->currency();
        $net = $vat = $gross = Money::zero($currency);
        foreach ($lines as $line) {
            $net = $net->add($line->lineNet());
            $vat = $vat->add($line->lineVat());
            $gross = $gross->add($line->lineGross());
        }

        return new self($net, $vat, $gross);
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

    public function currency(): Currency
    {
        return $this->gross->currency();
    }
}
