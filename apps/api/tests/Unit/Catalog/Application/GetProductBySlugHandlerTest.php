<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\GetProductBySlug;
use App\Catalog\Application\Query\GetProductBySlugHandler;
use App\Catalog\Domain\Product\ImageKey;
use PHPUnit\Framework\TestCase;

final class GetProductBySlugHandlerTest extends TestCase
{
    private const string FIRST = 'originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';
    private const string SECOND = 'originals/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg';

    public function testZoomImagesFollowTheImagesInOrderCountAndAltFromOneRead(): void
    {
        $source = $this->source([['key' => self::FIRST, 'alt' => 'Pierwsze'], ['key' => self::SECOND, 'alt' => 'Drugie']]);

        $details = $this->handler($source)(new GetProductBySlug('buty'));

        self::assertNotNull($details);
        self::assertSame(1, $source->reads);
        self::assertSame(['Pierwsze', 'Drugie'], array_map(static fn ($image): string => $image->alt, $details->images));
        self::assertSame(['Pierwsze', 'Drugie'], array_map(static fn ($image): string => $image->alt, $details->zoomImages));
        foreach ($details->zoomImages as $zoom) {
            self::assertSame('zoom', $zoom->size);
            self::assertSame(['webp'], array_keys($zoom->variants));
            self::assertSame([1280, 1920, 2560], array_column($zoom->variants['webp'], 'width'));
            self::assertSame(['alt', 'size', 'variants'], array_keys(get_object_vars($zoom)));
        }
        // Each zoom image is built from the key of the image at the same position.
        self::assertStringContainsString('zoom/'.self::FIRST.'/1280', $details->zoomImages[0]->variants['webp'][0]['url']);
        self::assertStringContainsString('zoom/'.self::SECOND.'/2560', $details->zoomImages[1]->variants['webp'][2]['url']);
        self::assertStringContainsString('product/'.self::FIRST.'/640', $details->images[0]->variants['webp'][0]['url']);
    }

    public function testAProductWithoutImagesHasNoZoomImages(): void
    {
        $details = $this->handler($this->source([]))(new GetProductBySlug('buty'));

        self::assertNotNull($details);
        self::assertSame([], $details->images);
        self::assertSame([], $details->zoomImages);
    }

    /**
     * @param list<array{key: string, alt: string}> $images
     */
    private function source(array $images): FakeProductDetailsSource
    {
        return new FakeProductDetailsSource($images);
    }

    private function handler(FakeProductDetailsSource $source): GetProductBySlugHandler
    {
        $urls = new class implements ImageUrlGenerator {
            public function url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string
            {
                return \sprintf('%s/%s/%d', $size->value, ImageKey::fromString($imageKey)->toString(), $width);
            }
        };

        return new GetProductBySlugHandler($source, $urls);
    }
}
