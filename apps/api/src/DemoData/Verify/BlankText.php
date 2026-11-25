<?php

declare(strict_types=1);

namespace App\DemoData\Verify;

/**
 * "No value" of a text column for the checks of demo-data:verify: NULL, the empty text, and a text without a visible
 * character (T3 of the rule of the texts, docs/features/api-input-contract/spec.md: only characters of the categories
 * Z, Cc and Cf and default ignorable code points). The API gives no value as an empty text, so the receiver does not
 * tell these forms apart, and a check that knows only NULL (or the ASCII `btrim`) passes a generator fault that writes
 * '' or a no-break space. DemoData may not use the rule itself (Shared\Domain\Text, Deptrac), so this is its copy of the
 * set of the characters, and a test compares it with `Text::normalize()` for every code point.
 *
 * PostgreSQL has no `\p{…}` classes, so the pattern names the invisible code points one by one (about thirty ranges,
 * found with PCRE, which is also what `Text` uses); a text is blank when it has no character outside them.
 */
final class BlankText
{
    private const string INVISIBLE = '/^[\p{Z}\p{Cc}\p{Cf}\p{DI}]$/Du';

    private static ?string $visible = null;

    private function __construct()
    {
    }

    /**
     * The POSIX regular expression of PostgreSQL that matches a text with at least one visible character.
     */
    public static function visiblePattern(): string
    {
        return self::$visible ??= '[^'.self::invisibleRanges().']';
    }

    /**
     * The SQL condition "the column holds no value": NULL, '' (cheap, no regular expression), or no visible character.
     *
     * @param string $quotedPattern `visiblePattern()` as an SQL literal (`Connection::quote()`)
     */
    public static function missing(string $column, string $quotedPattern): string
    {
        return \sprintf("(%1\$s IS NULL OR %1\$s = '' OR %1\$s !~ %2\$s)", $column, $quotedPattern);
    }

    /**
     * The code points the pattern leaves out, from U+0001 (PostgreSQL has no U+0000 in a text) without the surrogates.
     */
    private static function invisibleRanges(): string
    {
        $ranges = '';
        $start = null;
        for ($codePoint = 1; $codePoint <= 0x110000; ++$codePoint) {
            $invisible = $codePoint <= 0x10FFFF
                && ($codePoint < 0xD800 || $codePoint > 0xDFFF)
                && 1 === preg_match(self::INVISIBLE, mb_chr($codePoint, 'UTF-8'));
            if ($invisible && null === $start) {
                $start = $codePoint;
            } elseif (!$invisible && null !== $start) {
                $ranges .= self::escape($start).($start === $codePoint - 1 ? '' : '-'.self::escape($codePoint - 1));
                $start = null;
            }
        }

        return $ranges;
    }

    private static function escape(int $codePoint): string
    {
        return $codePoint <= 0xFFFF ? \sprintf('\u%04X', $codePoint) : \sprintf('\U%08X', $codePoint);
    }
}
