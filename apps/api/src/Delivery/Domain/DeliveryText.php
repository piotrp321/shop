<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * The rules of the texts of a delivery method (a copy of the patterns of Ordering's ControlCharacters and StaffNote; a
 * context shares no value objects). The control characters C0 and DEL, U+0085, U+2028 and U+2029, and a text that is
 * not valid UTF-8 (the pattern fails on it), are looked for in the value as it came: a trim would drop some of them at
 * the ends without a word, and the database refuses NUL. The ends are trimmed of Unicode whitespace (`\s` and `\p{Z}`,
 * so also NBSP), unlike `trim()` that knows only ASCII.
 */
final class DeliveryText
{
    private const string CONTROL = '/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u';
    private const string EDGES = '/^[\s\p{Z}]+|[\s\p{Z}]+$/Du';

    public static function hasForbiddenCharacters(string $text): bool
    {
        // 1: found; false: not valid UTF-8, which is no text either
        return 0 !== preg_match(self::CONTROL, $text);
    }

    /**
     * The text without Unicode whitespace at its ends; call it only on a text without forbidden characters.
     */
    public static function trim(string $text): string
    {
        return preg_replace(self::EDGES, '', $text) ?? $text;
    }
}
