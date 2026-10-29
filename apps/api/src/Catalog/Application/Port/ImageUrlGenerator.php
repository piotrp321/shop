<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;

interface ImageUrlGenerator
{
    /**
     * Signed URL of one variant of an original.
     *
     * @throws \InvalidArgumentException when the width or the format is not defined for the size
     */
    public function url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string;
}
