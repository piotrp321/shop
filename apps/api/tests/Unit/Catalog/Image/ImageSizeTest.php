<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Image;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use PHPUnit\Framework\TestCase;

final class ImageSizeTest extends TestCase
{
    public function testListingDefinition(): void
    {
        self::assertSame('1:1', ImageSize::Listing->aspectRatio());
        self::assertSame([256, 384, 512, 768], ImageSize::Listing->widths());
        self::assertSame([ImageFormat::Webp], ImageSize::Listing->formats());
        self::assertSame('fill', ImageSize::Listing->resize());
        self::assertSame('sm', ImageSize::Listing->gravity());
    }

    public function testProductDefinition(): void
    {
        self::assertSame('1:1', ImageSize::Product->aspectRatio());
        self::assertSame([640, 960, 1280, 1600], ImageSize::Product->widths());
        self::assertSame([ImageFormat::Webp], ImageSize::Product->formats());
        self::assertSame('fill', ImageSize::Product->resize());
        self::assertSame('sm', ImageSize::Product->gravity());
    }

    public function testThumbnailDefinition(): void
    {
        self::assertSame('1:1', ImageSize::Thumbnail->aspectRatio());
        self::assertSame([96, 192], ImageSize::Thumbnail->widths());
        self::assertSame([ImageFormat::Webp], ImageSize::Thumbnail->formats());
        self::assertSame('fill', ImageSize::Thumbnail->resize());
        self::assertSame('sm', ImageSize::Thumbnail->gravity());
    }

    public function testZoomDefinitionHasNoFixedAspectRatioAndFitsTheWholeImage(): void
    {
        self::assertNull(ImageSize::Zoom->aspectRatio());
        self::assertSame([1280, 1920, 2560], ImageSize::Zoom->widths());
        self::assertSame([ImageFormat::Webp], ImageSize::Zoom->formats());
        self::assertSame('fit', ImageSize::Zoom->resize());
        self::assertSame('zoom', ImageSize::Zoom->value);
    }

    public function testOtherSizesKeepTheirFixedAspectRatio(): void
    {
        foreach ([ImageSize::Listing, ImageSize::Product, ImageSize::Thumbnail] as $size) {
            self::assertSame('1:1', $size->aspectRatio());
        }
    }
}
