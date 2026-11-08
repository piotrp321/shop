<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\CategoryFacet;
use App\Catalog\Application\Query\ImageView;
use App\Catalog\Application\Query\ListProducts;
use App\Catalog\Application\Query\ListProductsHandler;
use App\Catalog\Application\Query\ProductFacets;
use App\Catalog\Application\Query\ProductListItem;
use App\Catalog\Application\Query\ProductPage;
use App\Catalog\Application\Search\OptionFacet;
use App\Catalog\Application\Search\OptionValue;
use App\Catalog\Application\Search\PriceRange;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSort;
use App\Catalog\Application\Search\SearchFacets;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Product\ProductStatus;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use PHPUnit\Framework\TestCase;

final class ListProductsHandlerTest extends TestCase
{
    private const string CATEGORY = '00000000-0000-4000-8000-0000000000a1';
    private const string RUNNING = '00000000-0000-4000-8000-0000000000a2';
    private const string TREKKING = '00000000-0000-4000-8000-0000000000a3';
    private const string GRANDCHILD = '00000000-0000-4000-8000-0000000000a4';
    private const string UNKNOWN = '00000000-0000-4000-8000-0000000000ff';
    private const string KEY = 'originals/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef.jpg';

    public function testUnknownCategoryIsNotFoundAndSearchIsNotCalled(): void
    {
        $search = new FakeProductSearch([self::document('Buty', null)], 1);

        try {
            $this->handle($search, new ListProducts(self::UNKNOWN, 1, 24));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
            self::assertNull($search->lastCriteria);
        }
    }

    public function testExistingCategoryWithoutProductsGivesAnEmptyPage(): void
    {
        $page = $this->handle(new FakeProductSearch([], 0), new ListProducts(self::CATEGORY, 1, 24));

        self::assertSame([], $page->items);
        self::assertSame(0, $page->total);
    }

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
            new ProductFacets([], [], null, 0),
        ), $page);
        self::assertNotNull($search->lastCriteria);
        self::assertSame(self::CATEGORY, $search->lastCriteria->categoryId);
        self::assertSame(3, $search->lastCriteria->page);
        self::assertSame(2, $search->lastCriteria->perPage);
    }

    public function testPageBeyondTheEndKeepsTheRealTotal(): void
    {
        $page = $this->handle(new FakeProductSearch([], 7), new ListProducts(null, 999, 24));

        self::assertSame([], $page->items);
        self::assertSame(999, $page->page);
        self::assertSame(7, $page->total);
    }

    public function testSearchParametersReachTheSearchAsCriteria(): void
    {
        $search = new FakeProductSearch();

        $this->handle($search, new ListProducts(
            null,
            2,
            12,
            phrase: 'buty',
            options: ['Kolor' => ['czarne', 'biały'], 'Rozmiar' => ['42']],
            priceMin: 10000,
            priceMax: 20000,
            availableOnly: true,
            sort: ProductSort::PriceDesc,
        ));

        $criteria = $search->lastCriteria;
        self::assertNotNull($criteria);
        self::assertSame('buty', $criteria->phrase);
        self::assertSame(['Kolor' => ['czarne', 'biały'], 'Rozmiar' => ['42']], $criteria->options);
        self::assertSame(10000, $criteria->priceMin);
        self::assertSame(20000, $criteria->priceMax);
        self::assertTrue($criteria->availableOnly);
        self::assertSame(ProductSort::PriceDesc, $criteria->sort());
        self::assertSame(2, $criteria->page);
        self::assertSame(12, $criteria->perPage);
    }

    public function testCategoryFacetCountsTheChildrenOfTheFilterCategoryInTreeOrder(): void
    {
        $search = new FakeProductSearch([], 5, new SearchFacets([self::TREKKING => 3, self::RUNNING => 2], [], null, 0));

        $page = $this->handle($search, new ListProducts(self::CATEGORY, 1, 24));

        // Tree order is by name (Bieganie before Trekking), and the grandchild is not counted.
        self::assertSame([self::RUNNING, self::TREKKING], $search->lastCriteria?->facetCategoryIds);
        self::assertEquals([
            new CategoryFacet(self::RUNNING, 'Bieganie', 'bieganie', 'buty/bieganie', 2),
            new CategoryFacet(self::TREKKING, 'Trekking', 'trekking', 'buty/trekking', 3),
        ], $page->facets->categories);
    }

    public function testCategoryFacetOmitsCategoriesWithoutProducts(): void
    {
        $search = new FakeProductSearch([], 2, new SearchFacets([self::TREKKING => 2], [], null, 0));

        $page = $this->handle($search, new ListProducts(self::CATEGORY, 1, 24));

        self::assertEquals([new CategoryFacet(self::TREKKING, 'Trekking', 'trekking', 'buty/trekking', 2)], $page->facets->categories);
    }

    public function testCategoryFacetWithoutAFilterCountsTheRoots(): void
    {
        $search = new FakeProductSearch([], 4, new SearchFacets([self::CATEGORY => 4], [], null, 0));

        $page = $this->handle($search, new ListProducts(null, 1, 24));

        self::assertSame([self::CATEGORY], $search->lastCriteria?->facetCategoryIds);
        self::assertEquals([new CategoryFacet(self::CATEGORY, 'Buty', 'buty', 'buty', 4)], $page->facets->categories);
    }

    public function testLeafCategoryHasNothingToCount(): void
    {
        $search = new FakeProductSearch([], 1, SearchFacets::empty());

        $page = $this->handle($search, new ListProducts(self::RUNNING, 1, 24));

        self::assertSame([], $search->lastCriteria?->facetCategoryIds);
        self::assertSame([], $page->facets->categories);
    }

    public function testOptionPriceAndAvailabilityFacetsAreHandedOn(): void
    {
        $options = [new OptionFacet('Kolor', [new OptionValue('czarne', 3), new OptionValue('biały', 0)])];
        $search = new FakeProductSearch([], 3, new SearchFacets([], $options, new PriceRange(9900, 24900), 2));

        $page = $this->handle($search, new ListProducts(null, 1, 24));

        self::assertSame($options, $page->facets->options);
        self::assertEquals(new PriceRange(9900, 24900), $page->facets->price);
        self::assertSame(2, $page->facets->availableCount);
    }

    private function handle(FakeProductSearch $search, ListProducts $query): ProductPage
    {
        $categories = new InMemoryCategoryRepository();
        $root = Category::createRoot(CategoryId::fromString(self::CATEGORY), new CategoryName('Buty'), new CategorySlug('buty'));
        $categories->save($root);
        // Saved in the reverse of the tree order on purpose.
        $trekking = Category::createChild(CategoryId::fromString(self::TREKKING), new CategoryName('Trekking'), new CategorySlug('trekking'), $root);
        $running = Category::createChild(CategoryId::fromString(self::RUNNING), new CategoryName('Bieganie'), new CategorySlug('bieganie'), $root);
        $categories->save($trekking);
        $categories->save($running);
        $categories->save(Category::createChild(CategoryId::fromString(self::GRANDCHILD), new CategoryName('Górskie'), new CategorySlug('gorskie'), $trekking));

        return (new ListProductsHandler($search, new class implements ImageUrlGenerator {
            public function url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string
            {
                return \sprintf('https://img.test/%s/%d/%s', $format->value, $width, $imageKey);
            }
        }, $categories))($query);
    }

    /**
     * @param array{key: string, alt: string}|null $mainImage
     */
    private static function document(string $name, ?array $mainImage): ProductDocument
    {
        return new ProductDocument('id-'.$name, 1, $name, 'slug-'.$name, 'Short '.$name, self::CATEGORY, [self::CATEGORY], [self::CATEGORY], $mainImage, [], [], null, ProductStatus::Published);
    }
}
