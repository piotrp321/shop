<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use App\Catalog\Domain\Product\OriginalImageType;

/**
 * The key of an original in the bucket, `originals/<sha256>.<extension>`, as FlysystemProductImageStorage writes it
 * (a contract test compares the two), so a file shared by many products is one object.
 */
final class ImageKeys
{
    private const array TYPES = [
        'image/jpeg' => OriginalImageType::Jpg,
        'image/png' => OriginalImageType::Png,
        'image/webp' => OriginalImageType::Webp,
        'image/avif' => OriginalImageType::Avif,
        'image/heic' => OriginalImageType::Heic,
    ];

    private function __construct()
    {
    }

    /**
     * The type of the contents by what they are (not by a name), null when it is not one of the originals the catalog accepts.
     */
    public static function typeOf(string $contents): ?OriginalImageType
    {
        $mime = new \finfo(\FILEINFO_MIME_TYPE)->buffer($contents);

        return false === $mime ? null : (self::TYPES[$mime] ?? null);
    }

    /**
     * @throws \InvalidArgumentException when the type of the contents is not one of the originals the catalog accepts
     */
    public static function forContents(string $contents): string
    {
        $type = self::typeOf($contents);
        if (null === $type) {
            throw new \InvalidArgumentException('The file is not an image type the catalog accepts.');
        }

        return \sprintf('originals/%s.%s', hash('sha256', $contents), $type->value);
    }
}
