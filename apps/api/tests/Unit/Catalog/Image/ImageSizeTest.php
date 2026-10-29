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
}
