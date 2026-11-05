<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The product of a variant of Catalog, as an order line copies it.
 */
final readonly class ProductId
{
    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self(UuidFormat::normalise($value, 'ProductId'));
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
