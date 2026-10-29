<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\ImageView;
use App\Catalog\Application\Query\ListProducts;
use App\Catalog\Application\Query\ListProductsHandler;
use App\Catalog\Application\Query\ProductListItem;
use App\Catalog\Application\Query\ProductPage;
use App\Catalog\Application\Search\ProductDocument;
use PHPUnit\Framework\TestCase;

final class ListProductsHandlerTest extends TestCase
{
    private const string CATEGORY = '00000000-0000-4000-8000-0000000000a1';
    private const string KEY = 'originals/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef.jpg';

    public function testProductWithoutImageHasNullImage(): void
    {
        $page = $this->handle(new FakeProductSearch([self::document('Buty', null)], 1), new ListProducts(null, 1, 24));

        self::assertCount(1, $page->items);
        self::assertNull($page->items[0]->image);
    }

    public function testProductWithImageGetsTheListingSizeWithWebpVariantsInAscendingWidths(): void
    {
        $page = $this->handle(new FakeProductSearch([self::document('Buty', ['key' => self::KEY, 'alt' => 'Biegaczka'])], 1), new ListProducts(null, 1, 24));

        $image = $page->items[0]->image;
        self::assertInstanceOf(ImageView::class, $image);
        self::assertSame('Biegaczka', $image->alt);
        self::assertSame('listing', $image->size);
        self::assertSame('1:1', $image->aspectRatio);
        self::assertSame(['webp'], array_keys($image->variants));
        self::assertSame([256, 384, 512, 768], array_column($image->variants['webp'], 'width'));
        self::assertSame(
            ['https://img.test/webp/256/'.self::KEY, 'https://img.test/webp/384/'.self::KEY, 'https://img.test/webp/512/'.self::KEY, 'https://img.test/webp/768/'.self::KEY],
            array_column($image->variants['webp'], 'url'),
        );
    }

    public function testMapsDocumentFieldsAndPageMetadata(): void
    {
        $search = new FakeProductSearch([self::document('Buty', null), self::document('Czapka', null)], 412);

        $page = $this->handle($search, new ListProducts(self::CATEGORY, 3, 2));

        self::assertEquals(new ProductPage(
            [
                new ProductListItem('id-Buty', 'Buty', 'slug-Buty', 'Short Buty', null),
                new ProductListItem('id-Czapka', 'Czapka', 'slug-Czapka', 'Short Czapka', null),
            ],
            3,
            2,
            412,
        ), $page);
        self::assertSame(['categoryId' => self::CATEGORY, 'page' => 3, 'perPage' => 2], $search->lastCall);
    }

    public function testPageBeyondTheEndKeepsTheRealTotal(): void
    {
        $page = $this->handle(new FakeProductSearch([], 7), new ListProducts(null, 999, 24));

        self::assertSame([], $page->items);
        self::assertSame(999, $page->page);
        self::assertSame(7, $page->total);
    }

    private function handle(FakeProductSearch $search, ListProducts $query): ProductPage
    {
        return (new ListProductsHandler($search, new class implements ImageUrlGenerator {
            public function url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string
            {
                return \sprintf('https://img.test/%s/%d/%s', $format->value, $width, $imageKey);
            }
        }))($query);
    }

    /**
     * @param array{key: string, alt: string}|null $mainImage
     */
    private static function document(string $name, ?array $mainImage): ProductDocument
    {
        return new ProductDocument('id-'.$name, 1, $name, 'slug-'.$name, 'Short '.$name, self::CATEGORY, [self::CATEGORY], [self::CATEGORY], $mainImage);
    }
}
