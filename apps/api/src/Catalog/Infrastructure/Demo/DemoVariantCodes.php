<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Demo;

use App\Catalog\Domain\Product\Slug;

/**
 * The codes of the variants of the demo catalog, computed without randomness from what the definition says.
 *
 * SKU: the first three letters of every word of the brand, the words of the model and the values of the variant (a
 * word of three characters or less whole), in capitals, joined with dashes, e.g. TAR-ASFALT-200-CZA-40. It starts with
 * the letters of the brand; the generator of DemoData starts its SKUs with a digit, so the two never collide.
 *
 * EAN: an EAN-13 of the prefix 2 (the numbers of restricted circulation of GS1, which identify no real item and so
 * have no gtin in the shop) and 11 digits taken from the hash of the key of the variant, with the check digit.
 */
final class DemoVariantCodes
{
    private const int EAN_BODY_MODULO = 100_000_000_000;

    /**
     * @param list<string> $values the values of the variant, one per axis
     */
    public static function sku(string $brand, string $model, array $values): string
    {
        $parts = array_map(static fn (string $word): string => strtoupper(substr($word, 0, 3)), self::words($brand));
        $parts[] = strtoupper(implode('-', self::words($model)));
        foreach ($values as $value) {
            foreach (self::words($value) as $word) {
                $parts[] = strtoupper(\strlen($word) <= 3 ? $word : substr($word, 0, 3));
            }
        }

        return implode('-', array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * @param string $variantKey the key of the variant, <slug>/<values> (VariantKey)
     */
    public static function ean(string $variantKey): string
    {
        $body = '2'.str_pad((string) (hexdec(substr(hash('sha256', $variantKey), 0, 12)) % self::EAN_BODY_MODULO), 11, '0', \STR_PAD_LEFT);

        $sum = 0;
        foreach (str_split($body) as $position => $digit) {
            $sum += (int) $digit * (0 === $position % 2 ? 1 : 3);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    /**
     * @return list<string> the lower case words of the text without diacritics
     */
    private static function words(string $text): array
    {
        $slug = Slug::slugify($text);

        return '' === $slug ? [] : explode('-', $slug);
    }
}
