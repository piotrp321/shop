<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ProductImage
{
    public const int MAX_ALT_LENGTH = 200;

    private string $alt;

    public function __construct(private ImageKey $key, string $alt, private int $position)
    {
        if (ProductText::hasForbiddenCharacters($alt)) {
            throw new \InvalidArgumentException('Image alt text must not contain forbidden characters.');
        }
        $alt = ProductText::trim($alt);
        if ('' === $alt) {
            throw new \InvalidArgumentException('Image alt text must not be empty.');
        }
        if (mb_strlen($alt) > self::MAX_ALT_LENGTH) {
            throw new \InvalidArgumentException('Image alt text must have at most '.self::MAX_ALT_LENGTH.' characters.');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Image position must not be negative.');
        }
        $this->alt = $alt;
    }

    public function key(): ImageKey
    {
        return $this->key;
    }

    public function alt(): string
    {
        return $this->alt;
    }

    public function position(): int
    {
        return $this->position;
    }
}
