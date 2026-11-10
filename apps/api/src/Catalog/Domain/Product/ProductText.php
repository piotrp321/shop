<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * The rules of the texts of a product (name, short description, alt text of an image, names of axes, values of
 * variants), one for the value objects and for the validator of the write endpoints.
 *
 * Forbidden are the control characters C0 and DEL (U+0000-U+001F, U+007F), U+0085, U+2028 and U+2029, and a text that
 * is not valid UTF-8 (the pattern fails on it). Only the short description may contain a line feed (U+000A). They are
 * looked for in the text as it came: a trim drops some of them at the ends, which would change the value silently, and
 * the database refuses NUL.
 *
 * The ends of a text are trimmed of Unicode whitespace (`\s` and `\p{Z}`, so also NBSP), unlike `trim()` that knows
 * only ASCII.
 */
final class ProductText
{
    private const string FORBIDDEN = '/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u';
    private const string FORBIDDEN_BUT_LINE_FEED = '/[\x00-\x09\x0B-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u';
    private const string EDGES = '/^[\s\p{Z}]+|[\s\p{Z}]+$/Du';

    public static function hasForbiddenCharacters(string $value, bool $allowLineFeed = false): bool
    {
        // 1: found; false: not valid UTF-8, which is no text either
        return 0 !== preg_match($allowLineFeed ? self::FORBIDDEN_BUT_LINE_FEED : self::FORBIDDEN, $value);
    }

    /**
     * The text without Unicode whitespace at its ends. A text that is not valid UTF-8 comes back as it is: the caller
     * refuses it with hasForbiddenCharacters() before.
     */
    public static function trim(string $value): string
    {
        return preg_replace(self::EDGES, '', $value) ?? $value;
    }

    /**
     * The number of characters of the text after the trim.
     */
    public static function length(string $value): int
    {
        return mb_strlen(self::trim($value));
    }
}
