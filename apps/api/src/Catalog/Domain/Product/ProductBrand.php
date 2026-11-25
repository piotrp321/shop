<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * The optional brand of a product: a one-line text by the rule of texts (Text), 0 to 60 characters once trimmed. A text
 * without a visible character is no brand (the empty text).
 */
final readonly class ProductBrand
{
    public const int MAX_LENGTH = 60;

    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(string $value): self
    {
        if (Text::hasForbiddenCharacters($value)) {
            throw new \InvalidArgumentException('ProductBrand must not contain forbidden characters.');
        }
        $value = Text::normalize($value);
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('ProductBrand must have at most '.self::MAX_LENGTH.' characters.');
        }

        return new self($value);
    }

    /**
     * A brand as stored, without the rule (spec api-input-contract, E7); only a write goes through of().
     */
    public static function stored(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
