<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

/**
 * The address segment of a category: lower case a-z and digits in words joined by single dashes, 1 to 60
 * characters. Unique among the siblings of the category, not globally.
 */
final readonly class CategorySlug
{
    private const int MAX_LENGTH = 60;
    private const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';

    public function __construct(private string $value)
    {
        if (\strlen($value) > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('CategorySlug is invalid.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
