<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * The idempotency key of a receipt, chosen by its sender (the `Idempotency-Key` of the panel, `seed:<productId>` of
 * the demo data). A key belongs to one receipt forever, whatever the product.
 */
final readonly class ReceiptKey
{
    public const int MAX_LENGTH = 100;
    private const string PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidReceiptKey
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidReceiptKey();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
