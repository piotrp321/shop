<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The control characters `\x00-\x1F` and `\x7F` have no place in a name, an address or an email address of an order.
 * They are looked for in the value as it came: a trim drops some of them (NUL, tab, line breaks) at the ends, which
 * would change the value silently, and the database refuses NUL.
 */
final class ControlCharacters
{
    public const string PATTERN = '/[\x00-\x1F\x7F]/';

    public static function in(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }
}
