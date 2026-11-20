<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The delivery of an order (docs/features/delivery/spec.md, W6): copies of the method as it was at the placement (id,
 * name, tracking template), never a reference, and its gross cost split over the VAT rates of the goods. The shares
 * add up to the cost, each above 0, one per rate; a free delivery costs 0 and has none. Net and VAT are the sums of
 * the shares, so net + VAT = gross to the grosz. That every rate is one of the lines is the rule of Order::place.
 */
final readonly class OrderDelivery
{
    public const int MAX_METHOD_NAME_LENGTH = 100;
    public const int MAX_TEMPLATE_LENGTH = 500;

    /**
     * @param list<OrderDeliveryShare> $shares
     */
    private function __construct(
        private string $methodId,
        private string $methodName,
        private ?string $trackingUrlTemplate,
        private Money $gross,
        private Money $net,
        private Money $vat,
        private array $shares,
    ) {
    }

    /**
     * @param string                   $methodId            a UUID, kept in lower case
     * @param string                   $methodName          1 to 100 characters
     * @param string|null              $trackingUrlTemplate 1 to 500 characters; null without one
     * @param int                      $gross               the cost in grosze, from 0
     * @param list<OrderDeliveryShare> $shares              by rate, one per rate
     *
     * @throws InvalidOrder a copy the columns cannot keep, or shares that are not the cost
     */
    public static function of(string $methodId, string $methodName, ?string $trackingUrlTemplate, int $gross, array $shares, Currency $currency): self
    {
        try {
            $methodId = UuidFormat::normalise($methodId, 'The id of a delivery method');
        } catch (\InvalidArgumentException $invalid) {
            throw new InvalidOrder($invalid->getMessage(), 0, $invalid);
        }
        $nameLength = mb_strlen($methodName);
        if (0 === $nameLength || $nameLength > self::MAX_METHOD_NAME_LENGTH) {
            throw new InvalidOrder(\sprintf('The name of a delivery method has 1 to %d characters.', self::MAX_METHOD_NAME_LENGTH));
        }
        if (null !== $trackingUrlTemplate && ('' === $trackingUrlTemplate || mb_strlen($trackingUrlTemplate) > self::MAX_TEMPLATE_LENGTH)) {
            throw new InvalidOrder(\sprintf('A tracking template has 1 to %d characters.', self::MAX_TEMPLATE_LENGTH));
        }

        $net = $vat = $sum = Money::zero($currency);
        $rates = [];
        foreach ($shares as $share) {
            if (isset($rates[$share->rateBp()])) {
                throw new InvalidOrder('A delivery has one share per VAT rate.');
            }
            $rates[$share->rateBp()] = true;
            $net = $net->add($share->net());
            $vat = $vat->add($share->vat());
            $sum = $sum->add($share->gross());
        }
        if ($sum->amount() !== $gross) {
            throw new InvalidOrder('The shares of a delivery add up to its cost; a free delivery has none.');
        }

        return new self($methodId, $methodName, $trackingUrlTemplate, $sum, $net, $vat, $shares);
    }

    public function methodId(): string
    {
        return $this->methodId;
    }

    public function methodName(): string
    {
        return $this->methodName;
    }

    public function trackingUrlTemplate(): ?string
    {
        return $this->trackingUrlTemplate;
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

    /**
     * @return list<OrderDeliveryShare>
     */
    public function shares(): array
    {
        return $this->shares;
    }
}
