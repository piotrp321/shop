<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The one rule of texts typed by people (docs/features/api-input-contract/spec.md, "Etap 2: reguła tekstu", T1–T5),
 * used by the value objects and the request classes of every context. Only the hygiene of a text is shared: the value
 * objects of a text stay the own concepts of each context, with their own limits and messages.
 *
 * - T1: a text that is not valid UTF-8, a control character (Unicode Cc: C0, DEL, C1) and the separators of lines and
 *   paragraphs are forbidden anywhere, looked for in the value as it came, before the trimming.
 * - T2: the ends lose Unicode white space (`\s` and the category Z) and U+200B, U+2060, U+FEFF and U+00AD; nothing
 *   inside the text is removed. Other format characters stay also at the ends, so the tags of a flag at the end of a
 *   text are kept (T5).
 * - T3: a text without a visible character once trimmed (only characters of the categories Z, Cc and Cf and default
 *   ignorable code points left) is empty. A private use character (Co) and a character the Unicode of PCRE does not
 *   know yet (Cn, e.g. a new emoji) count as visible.
 * - T4: the length counts code points after the trimming.
 */
final class Text
{
    private const string FORBIDDEN = '[\p{Cc}\x{2028}\x{2029}]';
    private const string EDGE = '[\s\p{Z}\x{200B}\x{2060}\x{FEFF}\x{00AD}]';
    private const string EDGES = '/^'.self::EDGE.'+|'.self::EDGE.'+$/Du';
    private const string INVISIBLE_ONLY = '/^[\p{Z}\p{Cc}\p{Cf}\p{DI}]*$/Du';

    /**
     * Whether the text breaks T1.
     *
     * @param string $allowed the characters of T1 the field allows, e.g. "\n" in a field of lines
     */
    public static function hasForbiddenCharacters(string $text, string $allowed = ''): bool
    {
        $exceptions = '' === $allowed ? '' : '(?!['.implode('', array_map(
            static fn (string $character): string => \sprintf('\x{%X}', mb_ord($character, 'UTF-8')),
            mb_str_split($allowed, 1, 'UTF-8'),
        )).'])';

        // 1: found; false: not valid UTF-8, which is no text either
        return 0 !== preg_match('/'.$exceptions.self::FORBIDDEN.'/u', $text);
    }

    /**
     * The text trimmed by T2, or the empty text when it is empty by T3. A text that is not valid UTF-8 is returned as
     * it came, for hasForbiddenCharacters() to refuse.
     */
    public static function normalize(string $text): string
    {
        $trimmed = preg_replace(self::EDGES, '', $text);
        if (null === $trimmed) {
            return $text;
        }

        return 1 === preg_match(self::INVISIBLE_ONLY, $trimmed) ? '' : $trimmed;
    }

    /**
     * The length of the normalized text in code points (T4).
     */
    public static function length(string $text): int
    {
        return mb_strlen(self::normalize($text), 'UTF-8');
    }
}
