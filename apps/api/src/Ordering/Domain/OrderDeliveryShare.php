<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The share of the cost of the delivery of an order at one VAT rate of its goods, as Pricing split it
 * (docs/features/delivery/spec.md, W3): above 0, net + VAT = gross.
 */
final readonly class OrderDeliveryShare
{
    private function __construct(
        private int $rateBp,
        private Money $gross,
        private Money $net,
        private Money $vat,
    ) {
    }

    /**
     * @param int $rateBp the VAT rate in basis points, 0 to 10 000
     *
     * @throws InvalidOrder a rate out of range, a share of 0, a negative amount or net + VAT other than the gross
     */
    public static function of(int $rateBp, int $gross, int $net, int $vat, Currency $currency): self
    {
        if ($rateBp < 0 || $rateBp > 10_000) {
            throw new InvalidOrder('A VAT rate is 0 to 10 000 basis points.');
        }
        if ($gross <= 0 || $net < 0 || $vat < 0 || $net + $vat !== $gross) {
            throw new InvalidOrder('A share of the delivery is above 0 and has net + vat = gross.');
        }

        return new self($rateBp, Money::of($gross, $currency), Money::of($net, $currency), Money::of($vat, $currency));
    }

    public function rateBp(): int
    {
        return $this->rateBp;
    }

    public function gross(): Money
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
}
