<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * The delivery of goods of a gross value after discounts (W2): the active methods in their order, each with its cost
 * and the amount missing to make it free, and the summary the cart shows ("Dostawa od X zł", "Do darmowej dostawy
 * brakuje Y zł"). The one place the cost of a delivery is computed (A2), for the cart and for the placement alike.
 */
final readonly class DeliveryQuote
{
    /**
     * @param list<DeliveryOption> $options
     */
    private function __construct(
        public array $options,
        public ?int $fromGross,
        public ?int $missingToFreeGross,
    ) {
    }

    /**
     * @param list<DeliveryMethod> $methods the inactive ones are left out
     *
     * @throws \InvalidArgumentException for a negative value of the goods, which is a logic error of the caller
     */
    public static function of(array $methods, int $goodsGross): self
    {
        if ($goodsGross < 0) {
            throw new \InvalidArgumentException('The value of the goods cannot be negative.');
        }

        $options = [];
        foreach (DeliveryMethodOrder::sort($methods) as $method) {
            if ($method->isActive()) {
                $options[] = new DeliveryOption($method, $method->costFor($goodsGross), $method->missingToFreeFor($goodsGross));
            }
        }
        if ([] === $options) {
            return new self([], null, null);
        }

        $from = min(array_map(static fn (DeliveryOption $option): int => $option->costGross, $options));
        $missing = array_values(array_filter(array_map(static fn (DeliveryOption $option): ?int => $option->missingToFreeGross, $options), static fn (?int $amount): bool => null !== $amount));

        return new self($options, $from, 0 === $from || [] === $missing ? null : min($missing));
    }
}
