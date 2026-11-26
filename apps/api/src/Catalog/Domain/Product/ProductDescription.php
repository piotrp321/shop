<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * The optional long description of a product: a plain text of paragraphs by the rule of texts (Text) that allows line
 * feeds (U+000A), 0 to 5000 characters once trimmed. A text without a visible character is no description. The store
 * shows it as it is: it is no markup.
 */
final readonly class ProductDescription
{
    public const int MAX_LENGTH = 5000;
    /** The character of T1 the description allows. */
    public const string ALLOWED = "\n";

    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(string $value): self
    {
        if (Text::hasForbiddenCharacters($value, self::ALLOWED)) {
            throw new \InvalidArgumentException('ProductDescription must not contain forbidden characters.');
        }
        $value = Text::normalize($value);
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('ProductDescription must have at most '.self::MAX_LENGTH.' characters.');
        }

        return new self($value);
    }

    /**
     * A description as stored, without the rule (spec api-input-contract, E7); only a write goes through of().
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
