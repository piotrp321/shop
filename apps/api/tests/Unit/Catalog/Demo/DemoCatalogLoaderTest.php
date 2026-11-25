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
        // The seed sets no positions: every category stays at 0, so the tree keeps the alphabetical order.
        self::assertSame([true, true, true, true, true], array_map(static fn (CreateCategory $category): bool => $category->bySeed, $categories));
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

    public function testProductsAreCreatedWithTheirBrandDescriptionParametersAndTheCodesOfTheirVariants(): void
    {
        $bus = new RecordingMessageBus();
        $catalog = new YamlDemoCatalogSource(self::DEFINITION, self::IMAGES)->load();

        new DemoCatalogLoader($bus, new InMemoryProductImageStorage())->load($catalog);

        $products = $bus->dispatchedOf(CreateProduct::class);
        self::assertSame('Tarvik', $products[0]->brand);
        self::assertSame("Lekkie buty na asfalt.\n\nButy do biegania po utwardzonych drogach.\n\nPodeszwa z pianki EVA tłumi wstrząsy.", $products[0]->description);
        self::assertSame(
            [['name' => 'Materiał cholewki', 'value' => 'siatka'], ['name' => 'Waga', 'value' => '250 g'], ['name' => 'Drop', 'value' => '8 mm']],
            $products[0]->parameters,
        );
        self::assertSame(['TAR-ASFALT-200-CZA-40', 'TAR-ASFALT-200-CZA-41', 'TAR-ASFALT-200-BIA-40', 'TAR-ASFALT-200-BIA-41'], array_column($products[0]->variants, 'sku'));
        foreach ($products as $product) {
            self::assertNotSame('', $product->brand, $product->name);
            foreach ($product->variants as $variant) {
                self::assertMatchesRegularExpression('/^2\d{12}$/D', $variant['ean'], $product->name);
            }
        }
        // A type without a description or parameters gives the sentence of the model and an empty list.
        self::assertSame('Plecak dzienny.', $products[6]->description);
        self::assertSame([], $products[6]->parameters);
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
