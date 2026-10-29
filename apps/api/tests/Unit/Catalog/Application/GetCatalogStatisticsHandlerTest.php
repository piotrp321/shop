<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Query\CatalogStatisticsView;
use App\Catalog\Application\Query\GetCatalogStatistics;
use App\Catalog\Application\Query\GetCatalogStatisticsHandler;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxCategory;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use App\Tests\Support\InMemory\InMemoryProductRepository;
use PHPUnit\Framework\TestCase;

final class GetCatalogStatisticsHandlerTest extends TestCase
{
    private InMemoryProductRepository $products;
    private InMemoryCategoryRepository $categories;

    protected function setUp(): void
    {
        $this->products = new InMemoryProductRepository();
        $this->categories = new InMemoryCategoryRepository();
    }

    public function testEmptyCatalogue(): void
    {
        $view = $this->handler($this->indexer(0))(new GetCatalogStatistics());

        self::assertEquals(new CatalogStatisticsView(0, 0, 0, 0), $view);
    }

    public function testCountsProductsCategoriesProductsWithoutImagesAndIndexedDocuments(): void
    {
        $root = $this->category('00000000-0000-4000-8000-000000000001');
        $this->category('00000000-0000-4000-8000-000000000002');
        $this->product('10000000-0000-4000-8000-000000000001', $root, withImage: true);
        $this->product('10000000-0000-4000-8000-000000000002', $root, withImage: false);
        $this->product('10000000-0000-4000-8000-000000000003', $root, withImage: false);

        $view = $this->handler($this->indexer(2))(new GetCatalogStatistics());

        self::assertEquals(new CatalogStatisticsView(3, 2, 2, 2), $view);
    }

    public function testIndexedProductsIsNullWhenTheSearchServiceIsUnavailable(): void
    {
        $root = $this->category('00000000-0000-4000-8000-000000000001');
        $this->product('10000000-0000-4000-8000-000000000001', $root, withImage: true);

        $view = $this->handler($this->indexer(new SearchUnavailable('down')))(new GetCatalogStatistics());

        self::assertEquals(new CatalogStatisticsView(1, 1, 0, null), $view);
    }

    public function testIndexedProductsIsNullWhenTheIndexCannotBeCounted(): void
    {
        $view = $this->handler($this->indexer(new SearchIndexError('no alias')))(new GetCatalogStatistics());

        self::assertNull($view->indexedProducts);
    }

    private function handler(ProductIndexer $indexer): GetCatalogStatisticsHandler
    {
        return new GetCatalogStatisticsHandler($this->products, $this->categories, $indexer);
    }

    private function indexer(int|\Throwable $result): ProductIndexer
    {
        return new class($result) implements ProductIndexer {
            public function __construct(private readonly int|\Throwable $result)
            {
            }

            public function index(ProductDocument $document): IndexOutcome
            {
                throw new \LogicException('Not used.');
            }

            public function countDocuments(): int
            {
                return $this->result instanceof \Throwable ? throw $this->result : $this->result;
            }
        };
    }

    private function category(string $id): Category
    {
        $category = Category::createRoot(CategoryId::fromString($id), new CategoryName('Category '.$id));
        $this->categories->save($category);

        return $category;
    }

    private function product(string $id, Category $category, bool $withImage): void
    {
        $images = $withImage
            ? ProductImages::fromList([new ProductImage(ImageKey::fromString('originals/'.hash('sha256', $id).'.jpg'), 'Alt', 0)])
            : ProductImages::fromList([]);

        $this->products->save(Product::create(
            ProductId::fromString($id),
            new ProductName('Product '.$id),
            new Slug('product-'.substr($id, 0, 8).'-'.substr($id, -4)),
            new ShortDescription(''),
            ProductCategories::create($category->id()),
            TaxCategory::Standard,
            $images,
        ));
    }
}
