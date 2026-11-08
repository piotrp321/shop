<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * The text of a discount code (decision Q4): once trimmed and in upper case, 3 to 32 characters of A–Z, 0–9 and the
 * dash. Codes are compared in this normalised form, so the case of the letters never matters.
 */
final readonly class DiscountCodeValue
{
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 32;
    private const string PATTERN = '/^[A-Z0-9-]{3,32}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidDiscountCode
     */
    public static function of(string $text): self
    {
        // The encoding first: a pattern with /u does not match invalid UTF-8 at all (preg_replace returns null).
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw InvalidDiscountCode::code();
        }
        // Trimmed of every Unicode space and invisible format character at the ends (a pasted code often has one);
        // anything else outside the alphabet, inside or not, is refused by the pattern. Upper case of ASCII only:
        // a letter outside A–Z stays as it is and is refused.
        $trimmed = preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $text);
        $value = strtoupper($trimmed ?? '');
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidDiscountCode::code();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
