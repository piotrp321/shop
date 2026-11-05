<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * A variant of Catalog, as an order line copies it.
 */
final readonly class VariantId
{
    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self(UuidFormat::normalise($value, 'VariantId'));
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
