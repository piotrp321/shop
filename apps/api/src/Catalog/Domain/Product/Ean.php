<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * The optional EAN of a variant: after the rule of texts (T1 on the value as it came, T2 trims the ends, a text without a
 * visible character is no code), only digits, 8, 12, 13 or 14 of them, with the check digit of GS1. Not unique: a seller may
 * give one code to several items.
 *
 * A code is a global trade item number unless it is a number of restricted circulation (RCN), which identifies an item
 * inside a company or a region only (GS1 General Specifications 26.0, section 2.1.11, docs/features/product-data/spec.md,
 * "EAN z puli obiegu ograniczonego"): gtin() is the code for the first and empty for the second.
 */
final readonly class Ean
{
    private const string FORMAT = '/^(?:\d{8}|\d{12,14})$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @return self|null null for a text without a visible character: the variant has no EAN
     *
     * @throws \InvalidArgumentException
     */
    public static function optional(string $text): ?self
    {
        if (Text::hasForbiddenCharacters($text)) {
            throw new \InvalidArgumentException('Ean must not contain forbidden characters.');
        }
        $value = Text::normalize($text);
        if ('' === $value) {
            return null;
        }
        if (1 !== preg_match(self::FORMAT, $value) || !self::hasCheckDigit($value)) {
            throw new \InvalidArgumentException('Ean must have 8, 12, 13 or 14 digits with the check digit of GS1.');
        }

        return new self($value);
    }

    /**
     * An EAN as stored, without the rule (spec api-input-contract, E7); only a write goes through optional().
     */
    public static function stored(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The code when it is a global trade item number, an empty text when it is a number of restricted circulation. A stored
     * code that is no digit string of a length of GS1 identifies nothing globally either.
     */
    public function gtin(): string
    {
        return 1 === preg_match(self::FORMAT, $this->value) && !$this->isRestrictedCirculation() ? $this->value : '';
    }

    /**
     * Whether the code is an RCN of section 2.1.11 (the table of the spec), read on the 14-digit form of the code (padded
     * with zeros on the left): RCN-8 of the prefixes 0 and 2 (six zeros, then 0 or 2), the GS1 prefix 04 and the U.P.C.
     * prefix 4, the U.P.C. company prefixes 001000 to 007999 and the prefixes 02 and 20 to 29. The first digit of the 14-digit
     * form is the indicator of a GTIN-14 and no rule looks at it except the first: RCN has no 14-digit form, so a code of 14
     * digits over an RCN-13 is no global number either. A stored code that is no digit string is no RCN
     * (gtin() is empty for it all the same).
     */
    public function isRestrictedCirculation(): bool
    {
        if (1 !== preg_match('/^\d{1,14}$/D', $this->value)) {
            return false;
        }
        $code = str_pad($this->value, 14, '0', \STR_PAD_LEFT);
        $companyPrefix = substr($code, 2, 6);

        return ('000000' === substr($code, 0, 6) && \in_array($code[6], ['0', '2'], true))
            || '04' === substr($code, 1, 2)
            || '2' === $code[1]
            || '02' === substr($code, 1, 2)
            || ($companyPrefix >= '001000' && $companyPrefix <= '007999');
    }

    private static function hasCheckDigit(string $code): bool
    {
        $sum = 0;
        $length = \strlen($code);
        // The weights 3 and 1 alternate from the digit next to the check digit, which has the weight 3.
        for ($position = 0; $position < $length - 1; ++$position) {
            $sum += (int) $code[$position] * (0 === ($length - 2 - $position) % 2 ? 3 : 1);
        }

        return (int) $code[$length - 1] === (10 - $sum % 10) % 10;
    }
}
