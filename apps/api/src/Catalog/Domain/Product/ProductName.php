<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * The name of a product: a text by the rule of texts (Text: no control characters, trimmed ends, at least one visible
 * character), 1 to 200 characters once trimmed.
 */
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
        if (Text::hasForbiddenCharacters($value)) {
            throw new \InvalidArgumentException('ProductName must not contain forbidden characters.');
        }
        $value = Text::normalize($value);
        if ('' === $value) {
            throw new \InvalidArgumentException('ProductName must not be empty.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('ProductName must have at most '.self::MAX_LENGTH.' characters.');
        }

        return new self($value);
    }

    /**
     * A name as stored, without the rule: a name stored before a stricter rule is still read (spec api-input-contract,
     * E7); only a write goes through of().
     */
    public static function reconstitute(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
