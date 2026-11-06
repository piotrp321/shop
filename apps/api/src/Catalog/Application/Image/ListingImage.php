<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\ImageView;

/**
 * The view of an image in the `listing` size.
 */
final class ListingImage
{
    public static function view(ImageUrlGenerator $urls, string $key, string $alt): ImageView
    {
        return SizedImage::view($urls, ImageSize::Listing, $key, $alt);
    }
}
