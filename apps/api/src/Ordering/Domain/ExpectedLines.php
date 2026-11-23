<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The lines of the cart the checkout showed when the customer placed the order (docs/features/checkout/spec.md, P2a):
 * 1 to Order::MAX_LINES variants, each once (compared in lower case), with 1 to OrderLine::MAX_QUANTITY units. Only
 * compared with the lines of the locked cart, as sets of pairs of variant and quantity (the order of the lines does not
 * matter); the order is placed from the cart, never from this list.
 */
final readonly class ExpectedLines
{
    /**
     * @param array<string, int> $quantities units by variant id in lower case, sorted by the id
     */
    private function __construct(private array $quantities)
    {
    }

    /**
     * @param list<array{variantId: string, quantity: int}> $lines
     *
     * @throws InvalidOrderInput
     */
    public static function of(array $lines): self
    {
        if ([] === $lines || \count($lines) > Order::MAX_LINES) {
            throw InvalidOrderInput::expectedLines();
        }
        $quantities = [];
        foreach ($lines as $line) {
            try {
                $variantId = VariantId::fromString($line['variantId'])->toString();
            } catch (\InvalidArgumentException) {
                throw InvalidOrderInput::expectedLines();
            }
            if (isset($quantities[$variantId]) || $line['quantity'] < 1 || $line['quantity'] > OrderLine::MAX_QUANTITY) {
                throw InvalidOrderInput::expectedLines();
            }
            $quantities[$variantId] = $line['quantity'];
        }

        return new self(self::sorted($quantities));
    }

    /**
     * Whether the lines of the cart are exactly these: the same variants with the same units, in any order. A cart
     * with a variant twice never matches.
     *
     * @param list<array{VariantId, int}> $lines the variant and the units of each line of the cart
     */
    public function matches(array $lines): bool
    {
        $quantities = [];
        foreach ($lines as [$variantId, $quantity]) {
            $id = $variantId->toString();
            if (isset($quantities[$id])) {
                return false;
            }
            $quantities[$id] = $quantity;
        }

        return self::sorted($quantities) === $this->quantities;
    }

    /**
     * `<variantId>*<quantity>` of every line, sorted by the variant and joined by commas: the same text for the same
     * list in any order and letter case (part of the RequestFingerprint).
     */
    public function canonical(): string
    {
        return implode(',', array_map(static fn (string $variantId, int $quantity): string => $variantId.'*'.$quantity, array_keys($this->quantities), $this->quantities));
    }

    /**
     * @param array<string, int> $quantities
     *
     * @return array<string, int>
     */
    private static function sorted(array $quantities): array
    {
        ksort($quantities, \SORT_STRING);

        return $quantities;
    }
}
