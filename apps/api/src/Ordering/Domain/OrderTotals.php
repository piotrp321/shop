<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The sums of an order: the values of its lines (the goods after the discount) and its delivery, the one place they
 * are computed. Every line and the delivery have net + vat = gross, so the sums have it too, to the grosz. The payment,
 * the refund and the summary of a customer take the gross with the delivery; the sales take the goods only
 * (goodsGross(), docs/features/delivery/spec.md, P14).
 */
final readonly class OrderTotals
{
    private function __construct(
        private Money $net,
        private Money $vat,
        private Money $gross,
        private Money $goodsGross,
    ) {
    }

    /**
     * @param list<OrderLine> $lines
     * @param ?OrderDelivery  $delivery null for an order stored without one (before the delivery)
     *
     * @throws InvalidOrder no line
     */
    public static function of(array $lines, ?OrderDelivery $delivery): self
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
        $goods = $gross;
        if (null !== $delivery) {
            $net = $net->add($delivery->net());
            $vat = $vat->add($delivery->vat());
            $gross = $gross->add($delivery->gross());
        }

        return new self($net, $vat, $gross, $goods);
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function vat(): Money
    {
        return $this->vat;
    }

    /**
     * The gross total with the delivery: what the customer pays and gets back.
     */
    public function gross(): Money
    {
        return $this->gross;
    }

    /**
     * The gross of the lines (the goods after the discount), without the delivery.
     */
    public function goodsGross(): Money
    {
        return $this->goodsGross;
    }

    public function currency(): Currency
    {
        return $this->gross->currency();
    }
}
