<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Catalog\Domain\Product\ProductText;

/**
 * 1 to 100 characters once trimmed of Unicode whitespace (also NBSP), without control characters (the rules of the
 * texts of a product, {@see ProductText}).
 */
final readonly class CategoryName
{
    public const int MAX_LENGTH = 100;

    private string $value;

    public function __construct(string $value)
    {
        if (ProductText::hasForbiddenCharacters($value)) {
            throw new \InvalidArgumentException('CategoryName must not contain forbidden characters.');
        }
        $value = ProductText::trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException('CategoryName must not be empty.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('CategoryName must have at most '.self::MAX_LENGTH.' characters.');
        }
        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }
}
