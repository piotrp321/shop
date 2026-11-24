<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * What an axis name or a variant value keeps after the first publication of its product (K5), and how the refusals
 * and the conflicts of the variants name a stored text.
 *
 * - A stored text the rule of texts takes keeps the text trimmed by the rule (Text), without regard to case, so a text
 *   stored with characters the rule trims is the same as the text without them.
 * - A stored text the rule refuses for a forbidden character (T1) keeps its correction: the text without the forbidden
 *   characters and without white space, compared with the given text without white space, trimmed and without regard
 *   to case ("czar" U+009F "ne" keeps „czarne” and „czar ne”, not „zielone”).
 * - A stored text without a visible character, also once its forbidden characters are gone, has no text to keep: any
 *   text may take its place.
 */
final class IdentityText
{
    private const string WHITE_SPACE = '/[\s\p{Z}]+/u';

    private function __construct()
    {
    }

    /**
     * Whether the given text keeps the identity of the stored one.
     */
    public static function keeps(string $stored, string $given): bool
    {
        if (!Text::hasForbiddenCharacters($stored)) {
            $identity = self::identity($stored);

            return null === $identity || $identity === self::identity($given);
        }
        $identity = self::identity(self::withoutWhiteSpace(self::withoutForbiddenCharacters($stored)));

        return null === $identity || $identity === self::identity(self::withoutWhiteSpace($given));
    }

    /**
     * Whether the stored text has an identity to keep: a text without a visible character, also once its forbidden
     * characters are gone, has none.
     */
    public static function has(string $stored): bool
    {
        return null !== self::identity(self::withoutForbiddenCharacters($stored));
    }

    /**
     * The stored text as a person reads it, for a message: without the characters the rule forbids (they are not
     * visible in the panel, and a message must not carry them) and without the ones it trims from the ends.
     */
    public static function shown(string $stored): string
    {
        return Text::normalize(self::withoutForbiddenCharacters($stored));
    }

    /**
     * Whether the stored text has a forbidden character: its correction is the text without it, or with a space in its
     * place.
     */
    public static function isRefused(string $stored): bool
    {
        return Text::hasForbiddenCharacters($stored);
    }

    /**
     * How a message names a variant by its stored values: the shown values joined with a comma, the default variant
     * for no values and a variant without a visible value for values that show nothing.
     *
     * @param list<string> $storedValues
     */
    public static function variant(array $storedValues): string
    {
        if ([] === $storedValues) {
            return 'Wariant domyślny';
        }
        $label = implode(', ', array_filter(array_map(self::shown(...), $storedValues), static fn (string $value): bool => '' !== $value));

        return '' === $label ? 'Wariant bez widocznej wartości' : \sprintf('Wariant „%s”', $label);
    }

    /**
     * Whether the texts given in place of the stored ones keep their identity, position by position.
     *
     * @param list<string> $stored
     * @param list<string> $given
     */
    public static function kept(array $stored, array $given): bool
    {
        if (\count($stored) !== \count($given)) {
            return false;
        }
        foreach ($stored as $position => $text) {
            if (!self::keeps($text, $given[$position])) {
                return false;
            }
        }

        return true;
    }

    private static function identity(string $text): ?string
    {
        $normalized = Text::normalize($text);

        return '' === $normalized ? null : mb_strtolower($normalized);
    }

    /**
     * The text without the characters the rule forbids, one by one, so the rule stays only in Text. A stored text is
     * valid UTF-8 (the database takes nothing else).
     */
    private static function withoutForbiddenCharacters(string $text): string
    {
        return implode('', array_filter(
            mb_str_split($text, 1, 'UTF-8'),
            static fn (string $character): bool => !Text::hasForbiddenCharacters($character),
        ));
    }

    private static function withoutWhiteSpace(string $text): string
    {
        return preg_replace(self::WHITE_SPACE, '', $text) ?? $text;
    }
}
