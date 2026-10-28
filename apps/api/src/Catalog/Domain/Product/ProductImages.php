<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ProductImages
{
    private const int MAX_IMAGES = 10;

    /**
     * @param list<ProductImage> $images
     */
    private function __construct(private array $images)
    {
    }

    /**
     * @param list<ProductImage> $images
     */
    public static function fromList(array $images): self
    {
        if (\count($images) > self::MAX_IMAGES) {
            throw new \InvalidArgumentException('A product can have at most '.self::MAX_IMAGES.' images.');
        }

        $keys = [];
        foreach ($images as $index => $image) {
            if ($image->position() !== $index) {
                throw new \InvalidArgumentException('Image position must equal its index in the list.');
            }
            if (isset($keys[$image->key()->toString()])) {
                throw new \InvalidArgumentException('Image keys must be unique.');
            }
            $keys[$image->key()->toString()] = true;
        }

        return new self($images);
    }

    public function main(): ?ProductImage
    {
        return $this->images[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->images;
    }

    /**
     * @return list<ProductImage>
     */
    public function toList(): array
    {
        return $this->images;
    }
}
