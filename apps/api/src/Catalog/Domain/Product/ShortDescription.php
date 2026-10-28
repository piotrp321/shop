<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ShortDescription
{
    private const int MAX_LENGTH = 500;

    public function __construct(private string $value)
    {
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('ShortDescription must have at most '.self::MAX_LENGTH.' characters.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
