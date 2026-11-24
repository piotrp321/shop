<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * What an axis name or a variant value keeps after the first publication of its product (K5): the text trimmed by the
 * rule of texts (Text), without regard to case, so a text stored with characters the rule trims is the same as the text
 * without them. A stored text the rule refuses (a forbidden character, or no visible character) has no identity to
 * keep: it may be corrected.
 */
final class IdentityText
{
    private function __construct()
    {
    }

    /**
     * @return string|null null for a text the rule refuses
     */
    public static function of(string $text): ?string
    {
        if (Text::hasForbiddenCharacters($text)) {
            return null;
        }
        $normalized = Text::normalize($text);

        return '' === $normalized ? null : mb_strtolower($normalized);
    }

    /**
     * Whether the texts given in place of the stored ones keep their identity: each stored text with an identity has
     * the same one given in its place.
     *
     * @param list<string> $stored
     * @param list<string> $given
     */
    public static function kept(array $stored, array $given): bool
    {
        foreach ($stored as $position => $text) {
            $identity = self::of($text);
            if (null !== $identity && $identity !== self::of($given[$position] ?? '')) {
                return false;
            }
        }

        return \count($stored) === \count($given);
    }
}
