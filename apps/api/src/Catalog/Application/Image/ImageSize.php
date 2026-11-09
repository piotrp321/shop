<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

/**
 * Closed list of image sizes. A new size is added here, never in a component. `fill` sizes crop to a fixed aspect
 * ratio; `fit` sizes (zoom) keep the whole image and have no aspect ratio. imgproxy never enlarges beyond the original.
 */
enum ImageSize: string
{
    case Listing = 'listing';
    case Product = 'product';
    case Thumbnail = 'thumbnail';
    case Zoom = 'zoom';

    /**
     * Width:height of every variant, null for a size that keeps the proportions of the original (the height follows
     * the width).
     */
    public function aspectRatio(): ?string
    {
        return match ($this) {
            self::Listing, self::Product, self::Thumbnail => '1:1',
            self::Zoom => null,
        };
    }

    /** @return list<int> */
    public function widths(): array
    {
        return match ($this) {
            self::Listing => [256, 384, 512, 768],
            self::Product => [640, 960, 1280, 1600],
            self::Thumbnail => [96, 192],
            self::Zoom => [1280, 1920, 2560],
        };
    }

    /** @return list<ImageFormat> */
    public function formats(): array
    {
        return match ($this) {
            self::Listing, self::Product, self::Thumbnail, self::Zoom => [ImageFormat::Webp],
        };
    }

    public function resize(): string
    {
        return match ($this) {
            self::Listing, self::Product, self::Thumbnail => 'fill',
            self::Zoom => 'fit',
        };
    }

    public function gravity(): string
    {
        return match ($this) {
            self::Listing, self::Product, self::Thumbnail, self::Zoom => 'sm',
        };
    }
}
