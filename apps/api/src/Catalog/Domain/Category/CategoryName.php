<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

final readonly class CategoryName
{
    private const int MAX_LENGTH = 100;

    public function __construct(private string $value)
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('CategoryName must not be empty.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('CategoryName must have at most '.self::MAX_LENGTH.' characters.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
