<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * An optional text of a few lines: it may contain line feeds, and of only whitespace it is empty.
 */
final readonly class ShortDescription
{
    public const int MAX_LENGTH = 500;

    private string $value;

    public function __construct(string $value)
    {
        if (ProductText::hasForbiddenCharacters($value, allowLineFeed: true)) {
            throw new \InvalidArgumentException('ShortDescription must not contain forbidden characters.');
        }
        $value = ProductText::trim($value);
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('ShortDescription must have at most '.self::MAX_LENGTH.' characters.');
        }
        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }
}
