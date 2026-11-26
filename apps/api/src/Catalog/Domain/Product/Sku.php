<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * The optional SKU of a variant, the code the seller gives it: after the rule of texts (T1 on the value as it came, T2
 * trims the ends, a text without a visible character is no code) and the letters turned into capitals (ASCII only),
 * 1 to 40 characters of A-Z, 0-9 and - _ . / that start with a letter or a digit. Unique among all the variants of all
 * the products: the handler asks before the write, the unique index of the table decides.
 */
final readonly class Sku
{
    public const int MAX_LENGTH = 40;

    private const string FORMAT = '/^[A-Z0-9][A-Z0-9\-_.\/]{0,39}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @return self|null null for a text without a visible character: the variant has no SKU
     *
     * @throws \InvalidArgumentException
     */
    public static function optional(string $text): ?self
    {
        if (Text::hasForbiddenCharacters($text)) {
            throw new \InvalidArgumentException('Sku must not contain forbidden characters.');
        }
        $text = Text::normalize($text);
        if ('' === $text) {
            return null;
        }
        // strtoupper() turns only the ASCII letters into capitals, whatever the locale.
        $value = strtoupper($text);
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw new \InvalidArgumentException('Sku must have from 1 to '.self::MAX_LENGTH.' characters: A-Z, 0-9 and - _ . / and start with a letter or a digit.');
        }

        return new self($value);
    }

    /**
     * A SKU as stored, without the rule (spec api-input-contract, E7); only a write goes through optional().
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
