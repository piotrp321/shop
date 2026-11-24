<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * An image of a product: the key of its original, its alt text by the rule of texts (Text), 1 to 200 characters once
 * trimmed, and its position.
 */
final readonly class ProductImage
{
    public const int MAX_ALT_LENGTH = 200;

    private function __construct(private ImageKey $key, private string $alt, private int $position)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(ImageKey $key, string $alt, int $position): self
    {
        if (Text::hasForbiddenCharacters($alt)) {
            throw new \InvalidArgumentException('Image alt text must not contain forbidden characters.');
        }
        $alt = Text::normalize($alt);
        if ('' === $alt) {
            throw new \InvalidArgumentException('Image alt text must not be empty.');
        }
        if (mb_strlen($alt) > self::MAX_ALT_LENGTH) {
            throw new \InvalidArgumentException('Image alt text must have at most '.self::MAX_ALT_LENGTH.' characters.');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Image position must not be negative.');
        }

        return new self($key, $alt, $position);
    }

    /**
     * An image as stored, without the rule of texts for its alt text (spec api-input-contract, E7); only a write goes
     * through of().
     */
    public static function reconstitute(ImageKey $key, string $alt, int $position): self
    {
        return new self($key, $alt, $position);
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
