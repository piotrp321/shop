<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

/**
 * Closed list of image sizes. A new size is added here, never in a component.
 */
enum ImageSize: string
{
    case Listing = 'listing';
    case Product = 'product';

    public function aspectRatio(): string
    {
        return match ($this) {
            self::Listing, self::Product => '1:1',
        };
    }

    /** @return list<int> */
    public function widths(): array
    {
        return match ($this) {
            self::Listing => [256, 384, 512, 768],
            self::Product => [640, 960, 1280, 1600],
        };
    }

    /** @return list<ImageFormat> */
    public function formats(): array
    {
        return match ($this) {
            self::Listing, self::Product => [ImageFormat::Webp],
        };
    }

    public function resize(): string
    {
        return match ($this) {
            self::Listing, self::Product => 'fill',
        };
    }

    public function gravity(): string
    {
        return match ($this) {
            self::Listing, self::Product => 'sm',
        };
    }
}
