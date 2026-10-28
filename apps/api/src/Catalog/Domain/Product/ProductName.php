<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ProductName
{
    private const int MAX_LENGTH = 200;

    public function __construct(private string $value)
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('ProductName must not be empty.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('ProductName must have at most '.self::MAX_LENGTH.' characters.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
