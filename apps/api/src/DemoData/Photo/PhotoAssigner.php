<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use App\DemoData\Catalog\ProductSpec;
use App\DemoData\Random\Prng;

/**
 * The photos of a product: none for an imageless one (every 50th), otherwise 1 to 3 different keys of the photo group
 * of its type (fewer when the group has fewer), in an order drawn from the stream of the part.
 */
final class PhotoAssigner
{
    public const string STREAM = 'photos';
    /** The weights of 1, 2 and 3 photos. */
    private const array COUNT_WEIGHTS = [50, 35, 15];

    private function __construct()
    {
    }

    /**
     * @param non-empty-list<string> $groupKeys
     *
     * @return list<array{key: string, alt: string}>
     */
    public static function assign(ProductSpec $spec, array $groupKeys, Prng $prng): array
    {
        if ($spec->imageless) {
            return [];
        }

        $count = min(1 + $prng->weighted(self::COUNT_WEIGHTS), \count($groupKeys));
        $images = [];
        foreach (\array_slice($prng->shuffle($groupKeys), 0, $count) as $position => $key) {
            $images[] = ['key' => $key, 'alt' => 0 === $position ? $spec->name : \sprintf('%s – zdjęcie %d', $spec->name, $position + 1)];
        }

        return $images;
    }
}
