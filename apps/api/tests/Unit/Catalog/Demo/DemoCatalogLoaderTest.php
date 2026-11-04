<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Demo\DemoCatalogLoader;
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
        // Two distinct files (sample.jpg, sample.png) are used by 7 products; each is stored once.
        self::assertCount(2, $storage->stored);
        $keys = array_column(array_merge(...array_map(static fn (CreateProduct $p): array => $p->images, $products)), 'key');
        self::assertEqualsCanonicalizing(array_unique($keys), $storage->stored);
        self::assertSame([], $products[2]->images);
        self::assertSame($storage->stored[0], $products[0]->images[0]['key']);
        self::assertSame('Biegacz na drodze', $products[0]->images[0]['alt']);
        // Every product is created with its one default variant, whose id is not the id of the product.
        foreach ($products as $product) {
            self::assertSame([], $product->variantAxes);
            self::assertCount(1, $product->variants);
            self::assertSame([], $product->variants[0]['values']);
            self::assertNotSame($product->id, $product->variants[0]['id']);
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
