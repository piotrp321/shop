<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The control characters C0 and DEL (`\x00-\x1F`, `\x7F`), U+0085 (next line), U+2028 and U+2029 (line and paragraph
 * separators), and a text that is not valid UTF-8 (the pattern fails on it), have no place in a name, an address, an
 * email address or a note of an order. They are looked for in the value as it came: a trim drops some of them
 * (NUL, tab, line breaks) at the ends, which would change the value silently, and the database refuses NUL.
 */
final class ControlCharacters
{
    public const string PATTERN = '/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u';

    public static function in(string $value): bool
    {
        // 1: found; false: not valid UTF-8, which is no text either
        return 0 !== preg_match(self::PATTERN, $value);
    }
}
