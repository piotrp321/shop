<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ImageKey
{
    private const string PATTERN = '#^originals/[a-f0-9]{64}\.jpg$#D';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('ImageKey is invalid.');
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
