<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use PHPUnit\Framework\TestCase;

final class ProductImagesTest extends TestCase
{
    public function testElevenImagesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductImages::fromList($this->images(11));
    }

    public function testTenImagesAreAccepted(): void
    {
        self::assertCount(10, ProductImages::fromList($this->images(10))->toList());
    }

    public function testGapInPositionsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductImages::fromList([$this->image(0, 0), $this->image(1, 2)]);
    }

    public function testPositionsNotMatchingListIndexAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductImages::fromList([$this->image(0, 1), $this->image(1, 0)]);
    }

    public function testDuplicateImageKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductImages::fromList([$this->image(0, 0), $this->image(0, 1)]);
    }

    public function testEmptyAltIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductImage($this->key(0), '', 0);
    }

    public function testNegativePositionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductImage($this->key(0), 'alt', -1);
    }

    public function testMainIsImageAtPositionZero(): void
    {
        $images = ProductImages::fromList($this->images(2));

        self::assertSame(0, $images->main()?->position());
        self::assertFalse($images->isEmpty());
    }

    public function testEmptyListHasNoMainImage(): void
    {
        $images = ProductImages::fromList([]);

        self::assertTrue($images->isEmpty());
        self::assertNull($images->main());
    }

    /** @return list<ProductImage> */
    private function images(int $count): array
    {
        $images = [];
        for ($i = 0; $i < $count; ++$i) {
            $images[] = $this->image($i, $i);
        }

        return $images;
    }

    private function image(int $keySeed, int $position): ProductImage
    {
        return new ProductImage($this->key($keySeed), 'Alt', $position);
    }

    private function key(int $seed): ImageKey
    {
        return ImageKey::fromString('originals/'.str_pad(dechex($seed), 64, '0', \STR_PAD_LEFT).'.jpg');
    }
}
