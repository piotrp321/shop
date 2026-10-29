<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoCatalogDefinition;
use App\Catalog\Application\Demo\DemoCatalogExpander;
use App\Catalog\Application\Demo\DemoProduct;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

final class DemoCatalogExpanderTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/demo/catalog.yaml';
    private const string NAMESPACE = '11111111-2222-4333-8444-555555555555';

    public function testTwoExpansionsAreIdentical(): void
    {
        $definition = DemoCatalogDefinition::fromFile(self::FIXTURE);

        self::assertEquals(
            new DemoCatalogExpander()->expand($definition),
            new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURE)),
        );
    }

    public function testProductsFollowTemplateOrderThenBrandModelColor(): void
    {
        $products = $this->expand()->products;

        self::assertCount(10, $products);
        self::assertSame('Buty Tarvik Asfalt 200 czarne', $products[0]->name);
        self::assertSame('Buty Tarvik Asfalt 200 białe', $products[1]->name);
        self::assertSame('Buty Tarvik Dystans 400 czarne', $products[2]->name);
        self::assertSame('Buty Norvane Asfalt 200 czarne', $products[4]->name);
        self::assertSame('Plecak Tarvik Ząb 20 żółty', $products[8]->name);
        self::assertSame('Lekkie buty na asfalt.', $products[0]->shortDescription);
        self::assertSame('standard', $products[0]->taxCategory);
        self::assertSame('food', $products[8]->taxCategory);
    }

    public function testSlugIsTransliteratedNameWithoutPolishCharacters(): void
    {
        $products = $this->expand()->products;

        self::assertSame('buty-tarvik-asfalt-200-czarne', $products[0]->slug);
        self::assertSame('buty-tarvik-asfalt-200-biale', $products[1]->slug);
        self::assertSame('plecak-tarvik-zab-20-zolty', $products[8]->slug);
    }

    public function testIdsAreUuidV5OfSlugsInTheNamespace(): void
    {
        $catalog = $this->expand();
        $namespace = Uuid::fromString(self::NAMESPACE);

        foreach ($catalog->products as $product) {
            self::assertSame(Uuid::v5($namespace, 'product:'.$product->slug)->toRfc4122(), $product->id);
        }
        $byPath = [];
        foreach ($catalog->categories as $category) {
            self::assertSame(Uuid::v5($namespace, 'category:'.$category->slugPath)->toRfc4122(), $category->id);
            $byPath[$category->slugPath] = $category->id;
        }
        self::assertSame($byPath['bieganie/buty/szosowe'], $catalog->products[0]->mainCategoryId);
    }

    public function testDuplicateSlugThrowsWithThatSlug(): void
    {
        $data = $this->with(['templates', 0, 'colors'], ['czarne', 'Czarne']);

        try {
            new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data));
            self::fail('A duplicate slug must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('buty-tarvik-asfalt-200-czarne', $e->getMessage());
        }
    }

    public function testEveryNthProductHasNoImagesAndOthersTakePoolByIndex(): void
    {
        $products = $this->expand()->products;

        // imagelessEvery is 3: expansion indexes 2, 5 and 8 (0-based) have no images.
        $imageless = array_keys(array_filter($products, static fn (DemoProduct $p): bool => [] === $p->images));
        self::assertSame([2, 5, 8], $imageless);

        // The first template's pool is [sample.jpg, sample.png]; the index is global, so index 3 takes pool[1].
        self::assertSame('sample.jpg', $products[0]->images[0]['file']);
        self::assertSame('Biegacz na drodze', $products[0]->images[0]['alt']);
        self::assertSame('sample.png', $products[1]->images[0]['file']);
        self::assertSame('sample.png', $products[3]->images[0]['file']);
        self::assertSame('sample.jpg', $products[6]->images[0]['file']);
        self::assertSame('sample.png', $products[7]->images[0]['file']);
        // The second template has a single image: index 9 (the product after the imageless one) takes it.
        self::assertSame('sample.jpg', $products[9]->images[0]['file']);
        self::assertCount(1, $products[9]->images);
    }

    public function testParentCategoryComesBeforeItsChildren(): void
    {
        $seen = [];
        foreach ($this->expand()->categories as $category) {
            self::assertTrue(null === $category->parentId || isset($seen[$category->parentId]), $category->slugPath);
            $seen[$category->id] = true;
        }
        self::assertSame(
            ['bieganie', 'bieganie/buty', 'bieganie/buty/szosowe', 'turystyka', 'turystyka/plecaki'],
            array_map(static fn ($c): string => $c->slugPath, $this->expand()->categories),
        );
    }

    public function testAdditionalCategoriesAreProductCategoryIds(): void
    {
        $catalog = $this->expand();
        $plecaki = null;
        foreach ($catalog->categories as $category) {
            if ('turystyka/plecaki' === $category->slugPath) {
                $plecaki = $category->id;
            }
        }

        self::assertSame([$plecaki], $catalog->products[0]->additionalCategoryIds);
        self::assertSame([], $catalog->products[8]->additionalCategoryIds);
    }

    public function testUnknownAdditionalCategoryPathThrowsWithThatPath(): void
    {
        $data = $this->with(['templates', 0, 'additionalCategories'], ['rower/nie-ma']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rower/nie-ma');

        new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data));
    }

    public function testUnknownMainCategoryPathThrowsWithThatPath(): void
    {
        $data = $this->with(['templates', 1, 'category'], 'turystyka/namioty');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('turystyka/namioty');

        new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data));
    }

    public function testDuplicateCategoryPathThrows(): void
    {
        $data = $this->with(['categories', 1, 'slug'], 'bieganie');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bieganie');

        new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data));
    }

    public function testDefinitionWithoutImagelessEveryIsRejected(): void
    {
        $data = $this->with(['imagelessEvery'], 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('imagelessEvery');

        DemoCatalogDefinition::fromArray($data);
    }

    public function testMissingFileIsReportedWithItsPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nie-ma-takiego.yaml');

        DemoCatalogDefinition::fromFile(__DIR__.'/nie-ma-takiego.yaml');
    }

    private function expand(): DemoCatalog
    {
        return new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURE));
    }

    /**
     * The fixture with the value at $path replaced.
     *
     * @param list<string|int> $path
     *
     * @return array<mixed>
     */
    private function with(array $path, mixed $value): array
    {
        $data = Yaml::parseFile(self::FIXTURE);
        self::assertIsArray($data);

        return self::set($data, $path, $value);
    }

    /**
     * @param array<mixed>     $data
     * @param list<string|int> $path
     *
     * @return array<mixed>
     */
    private static function set(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        \assert(null !== $key);
        $child = $data[$key] ?? null;
        $data[$key] = [] === $path ? $value : self::set(\is_array($child) ? $child : [], $path, $value);

        return $data;
    }
}
