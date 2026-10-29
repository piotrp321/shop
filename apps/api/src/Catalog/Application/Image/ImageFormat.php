<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

/**
 * Output formats. The value is the file extension used in the imgproxy path.
 */
enum ImageFormat: string
{
    case Webp = 'webp';
}
