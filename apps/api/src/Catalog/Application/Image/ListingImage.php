<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\ImageView;

/**
 * The view of an image in the `listing` size: signed URLs of every format and width of the size, widths ascending.
 */
final class ListingImage
{
    public static function view(ImageUrlGenerator $urls, string $key, string $alt): ImageView
    {
        $size = ImageSize::Listing;
        $variants = [];
        foreach ($size->formats() as $format) {
            $widths = $size->widths();
            sort($widths);
            foreach ($widths as $width) {
                $variants[$format->value][] = ['width' => $width, 'url' => $urls->url($key, $size, $width, $format)];
            }
        }

        return new ImageView($alt, $size->value, $size->aspectRatio(), $variants);
    }
}
