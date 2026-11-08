<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The discount code an order was placed with, copied from the quote of Pricing (decision A2): its text, kind and value
 * (the percent, or the amount in grosze) and the discount it gave the order. The order keeps the copy, never a
 * reference, so a later change of the code changes nothing of the order.
 */
final readonly class OrderDiscount
{
    public const int MAX_CODE_LENGTH = 32;

    private function __construct(
        private string $code,
        private OrderDiscountKind $kind,
        private int $value,
        private Money $amount,
    ) {
    }

    /**
     * @param int $value the percent of a percent code, the amount in grosze of an amount code
     *
     * @throws \InvalidArgumentException
     */
    public static function of(string $code, OrderDiscountKind $kind, int $value, Money $amount): self
    {
        $length = \strlen($code);
        if ($length < 1 || $length > self::MAX_CODE_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A discount code has 1 to %d characters.', self::MAX_CODE_LENGTH));
        }
        if ($value < 1) {
            throw new \InvalidArgumentException('The value of a discount code is positive.');
        }
        if ($amount->amount() < 1) {
            throw new \InvalidArgumentException('A discount code applied to an order takes at least one grosz off.');
        }

        return new self($code, $kind, $value, $amount);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function kind(): OrderDiscountKind
    {
        return $this->kind;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function amount(): Money
    {
        return $this->amount;
    }
}
