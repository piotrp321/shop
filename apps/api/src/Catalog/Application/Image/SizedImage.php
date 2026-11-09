<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\ImageView;
use App\Catalog\Application\Query\ZoomImageView;

/**
 * The view of an image in one of the sizes: signed URLs of every format and width of the size, widths ascending.
 */
final class SizedImage
{
    /**
     * @param ImageSize $size a size with a fixed aspect ratio (not `zoom`, see zoomView())
     */
    public static function view(ImageUrlGenerator $urls, ImageSize $size, string $key, string $alt): ImageView
    {
        $aspectRatio = $size->aspectRatio();
        if (null === $aspectRatio) {
            throw new \LogicException(\sprintf('The size "%s" has no aspect ratio, use zoomView().', $size->value));
        }

        return new ImageView($alt, $size->value, $aspectRatio, self::variants($urls, $size, $key));
    }

    public static function zoomView(ImageUrlGenerator $urls, string $key, string $alt): ZoomImageView
    {
        return new ZoomImageView($alt, ImageSize::Zoom->value, self::variants($urls, ImageSize::Zoom, $key));
    }

    /**
     * @return array<string, list<array{width: int, url: string}>>
     */
    private static function variants(ImageUrlGenerator $urls, ImageSize $size, string $key): array
    {
        $variants = [];
        foreach ($size->formats() as $format) {
            $widths = $size->widths();
            sort($widths);
            foreach ($widths as $width) {
                $variants[$format->value][] = ['width' => $width, 'url' => $urls->url($key, $size, $width, $format)];
            }
        }

        return $variants;
    }
}
