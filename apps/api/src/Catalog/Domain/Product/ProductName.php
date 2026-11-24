<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ProductName
{
    public const int MAX_LENGTH = 200;

    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(string $value): self
    {
        if (ProductText::hasForbiddenCharacters($value)) {
            throw new \InvalidArgumentException('ProductName must not contain forbidden characters.');
        }
        $value = ProductText::trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException('ProductName must not be empty.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('ProductName must have at most '.self::MAX_LENGTH.' characters.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
