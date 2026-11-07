<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Demo\DemoCatalogLoader;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Demo\YamlDemoCatalogSource;
use App\Tests\Support\InMemory\InMemoryProductImageStorage;
use App\Tests\Support\InMemory\RecordingMessageBus;
use PHPUnit\Framework\TestCase;

final class DemoCatalogLoaderTest extends TestCase
{
    private const string DEFINITION = __DIR__.'/../../../Fixtures/demo/catalog.yaml';
    private const string IMAGES = __DIR__.'/../../../Fixtures/images';

    public function testLoadsCategoriesParentFirstThenProductsAndStoresEachFileOnce(): void
    {
        $bus = new RecordingMessageBus();
        $storage = new InMemoryProductImageStorage();
        $catalog = new YamlDemoCatalogSource(self::DEFINITION, self::IMAGES)->load();

        new DemoCatalogLoader($bus, $storage)->load($catalog);

        $categories = $bus->dispatchedOf(CreateCategory::class);
        $products = $bus->dispatchedOf(CreateProduct::class);
        self::assertCount(5, $categories);
        self::assertCount(10, $products);
        // Categories first, then products.
        self::assertSame([...$categories, ...$products], $bus->dispatched);
        self::assertNull($categories[0]->parentId);
        self::assertSame($categories[0]->id, $categories[1]->parentId);
        // Every category is created with the slug of its node in the definition.
        self::assertSame(
            ['bieganie', 'buty', 'szosowe', 'turystyka', 'plecaki'],
            array_map(static fn (CreateCategory $category): string => $category->slug, $categories),
        );
        // Two distinct files (sample.jpg, sample.png) are used by 7 products; each is stored once.
        self::assertCount(2, $storage->stored);
        $keys = array_column(array_merge(...array_map(static fn (CreateProduct $p): array => $p->images, $products)), 'key');
        self::assertEqualsCanonicalizing(array_unique($keys), $storage->stored);
        self::assertSame([], $products[2]->images);
        self::assertSame($storage->stored[0], $products[0]->images[0]['key']);
        self::assertSame('Biegacz na drodze', $products[0]->images[0]['alt']);
        // Products are created with their axes and variants; a variant id is never the id of its product.
        self::assertSame(['Kolor', 'Rozmiar'], $products[0]->variantAxes);
        self::assertCount(4, $products[0]->variants);
        self::assertSame(['czarne', '40'], $products[0]->variants[0]['values']);
        self::assertSame(['Kolor'], $products[6]->variantAxes);
        self::assertCount(2, $products[6]->variants);
        self::assertSame([], $products[8]->variantAxes);
        self::assertCount(1, $products[8]->variants);
        self::assertSame([], $products[8]->variants[0]['values']);
        // The demo catalog is in the storefront from the start, also its products without images; nobody made it.
        self::assertSame([ProductStatus::Published], array_values(array_unique(array_map(static fn (CreateProduct $p): ProductStatus => $p->status, $products), \SORT_REGULAR)));
        self::assertSame([null], array_values(array_unique(array_map(static fn (CreateProduct $p): ?string => $p->actorId, $products), \SORT_REGULAR)));
        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                self::assertNotSame($product->id, $variant['id']);
            }
        }
    }

    public function testSourceFailsOnMissingImageFile(): void
    {
        $source = new YamlDemoCatalogSource(self::DEFINITION, __DIR__.'/no-such-dir');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sample.jpg');

        $source->load();
    }

    public function testSourceFailsOnMissingDefinition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no-such-catalog.yaml');

        new YamlDemoCatalogSource(__DIR__.'/no-such-catalog.yaml', self::IMAGES)->load();
    }
}
