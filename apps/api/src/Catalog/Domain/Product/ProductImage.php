<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ProductImage
{
    public function __construct(private ImageKey $key, private string $alt, private int $position)
    {
        if ('' === trim($alt)) {
            throw new \InvalidArgumentException('Image alt text must not be empty.');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Image position must not be negative.');
        }
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
