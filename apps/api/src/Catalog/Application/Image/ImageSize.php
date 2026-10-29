<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

/**
 * Closed list of image sizes. A new size is added here, never in a component.
 */
enum ImageSize: string
{
    case Listing = 'listing';

    public function aspectRatio(): string
    {
        return match ($this) {
            self::Listing => '1:1',
        };
    }

    /** @return list<int> */
    public function widths(): array
    {
        return match ($this) {
            self::Listing => [256, 384, 512, 768],
        };
    }

    /** @return list<ImageFormat> */
    public function formats(): array
    {
        return match ($this) {
            self::Listing => [ImageFormat::Webp],
        };
    }

    public function resize(): string
    {
        return match ($this) {
            self::Listing => 'fill',
        };
    }

    public function gravity(): string
    {
        return match ($this) {
            self::Listing => 'sm',
        };
    }
}
