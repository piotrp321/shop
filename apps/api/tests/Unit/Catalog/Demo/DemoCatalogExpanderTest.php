<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoProduct;
use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

final class DemoCatalogExpanderTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/demo/catalog.yaml';
    private const string IMAGES = '/images';
    private const string NAMESPACE = '11111111-2222-4333-8444-555555555555';

    public function testTwoExpansionsAreIdentical(): void
    {
        $definition = DemoCatalogDefinition::fromFile(self::FIXTURE);

        self::assertEquals(
            new DemoCatalogExpander()->expand($definition, self::IMAGES),
            new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURE), self::IMAGES),
        );
    }

    public function testProductsFollowTemplateOrderThenBrandThenModelWithoutTheVariantValues(): void
    {
        $products = $this->expand()->products;

        self::assertCount(10, $products);
        self::assertSame('Buty Tarvik Asfalt 200', $products[0]->name);
        self::assertSame('Buty Tarvik Dystans 400', $products[1]->name);
        self::assertSame('Buty Tarvik Tempo 600', $products[2]->name);
        self::assertSame('Buty Norvane Asfalt 200', $products[3]->name);
        self::assertSame('Plecak Tarvik Ząb 20', $products[6]->name);
        self::assertSame('Plecak Tarvik Ząb 30', $products[7]->name);
        self::assertSame('Hantle Lumora Para 10', $products[8]->name);
        self::assertSame('Hantle Dravik Para 10', $products[9]->name);
        self::assertSame('Lekkie buty na asfalt.', $products[0]->shortDescription);
        self::assertSame('standard', $products[0]->taxClass);
        self::assertSame('freeze_dried_meal', $products[6]->taxClass);
    }

    public function testSlugIsTransliteratedNameWithoutPolishCharacters(): void
    {
        $products = $this->expand()->products;

        self::assertSame('buty-tarvik-asfalt-200', $products[0]->slug);
        self::assertSame('buty-norvane-asfalt-200', $products[3]->slug);
        self::assertSame('plecak-tarvik-zab-20', $products[6]->slug);
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

    public function testVariantsAreTheProductOfTheAxisValuesInFileOrder(): void
    {
        $product = $this->expand()->products[0];
        $namespace = Uuid::fromString(self::NAMESPACE);

        self::assertSame(['Kolor', 'Rozmiar'], $product->variantAxes);
        self::assertSame(
            [
                ['id' => Uuid::v5($namespace, 'variant:buty-tarvik-asfalt-200/czarne-40')->toRfc4122(), 'values' => ['czarne', '40']],
                ['id' => Uuid::v5($namespace, 'variant:buty-tarvik-asfalt-200/czarne-41')->toRfc4122(), 'values' => ['czarne', '41']],
                ['id' => Uuid::v5($namespace, 'variant:buty-tarvik-asfalt-200/biale-40')->toRfc4122(), 'values' => ['białe', '40']],
                ['id' => Uuid::v5($namespace, 'variant:buty-tarvik-asfalt-200/biale-41')->toRfc4122(), 'values' => ['białe', '41']],
            ],
            $product->variants,
        );
    }

    public function testProductWithOneAxisHasAVariantPerValue(): void
    {
        $product = $this->expand()->products[6];
        $namespace = Uuid::fromString(self::NAMESPACE);

        self::assertSame(['Kolor'], $product->variantAxes);
        self::assertSame(
            [
                ['id' => Uuid::v5($namespace, 'variant:plecak-tarvik-zab-20/zolty')->toRfc4122(), 'values' => ['żółty']],
                ['id' => Uuid::v5($namespace, 'variant:plecak-tarvik-zab-20/zielony')->toRfc4122(), 'values' => ['zielony']],
            ],
            $product->variants,
        );
    }

    public function testProductWithoutAxesHasOneDefaultVariantWithAnIdOfItsOwn(): void
    {
        $product = $this->expand()->products[8];
        $namespace = Uuid::fromString(self::NAMESPACE);

        self::assertSame([], $product->variantAxes);
        self::assertSame(
            [['id' => Uuid::v5($namespace, 'variant:hantle-lumora-para-10/standard')->toRfc4122(), 'values' => []]],
            $product->variants,
        );
        self::assertNotSame($product->id, $product->variants[0]['id']);
    }

    public function testVariantIdsAreUniqueInTheWholeCatalog(): void
    {
        $variantIds = [];
        foreach ($this->expand()->products as $product) {
            foreach ($product->variants as $variant) {
                $variantIds[] = $variant['id'];
            }
        }

        self::assertCount(4 * 6 + 2 * 2 + 2, $variantIds);
        self::assertSame($variantIds, array_values(array_unique($variantIds)));
    }

    public function testAxisValuesDifferingOnlyInThePunctuationOfTheKeyAreRejectedWithThatKey(): void
    {
        $data = $this->with(['templates', 0, 'axisValues', 1], ['1,5', '1.5']);

        try {
            new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
            self::fail('A repeated variant key must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('variant key "buty-tarvik-asfalt-200/czarne-1-5"', $e->getMessage());
        }
    }

    public function testRepeatedVariantKeyOfValuesDifferingInCaseThrowsWithThatKey(): void
    {
        $data = $this->with(['templates', 1, 'axisValues', 0], ['żółty', 'Żółty']);

        try {
            new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
            self::fail('A repeated variant key must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('variant key "plecak-tarvik-zab-20/zolty"', $e->getMessage());
        }
    }

    public function testDuplicateSlugThrowsWithThatSlug(): void
    {
        $data = $this->with(['templates', 0, 'models', 1, 'name'], 'Asfalt 200');

        try {
            new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
            self::fail('A duplicate slug must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('product slug "buty-tarvik-asfalt-200"', $e->getMessage());
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
        self::assertSame('sample.jpg', $products[4]->images[0]['file']);
        // The second and third templates have a single image each: it is the one of every product of the template.
        self::assertSame('sample.jpg', $products[6]->images[0]['file']);
        self::assertSame('sample.jpg', $products[7]->images[0]['file']);
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
        self::assertSame([], $catalog->products[6]->additionalCategoryIds);
    }

    public function testUnknownAdditionalCategoryPathThrowsWithThatPath(): void
    {
        $data = $this->with(['templates', 0, 'additionalCategories'], ['rower/nie-ma']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rower/nie-ma');

        new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
    }

    public function testUnknownMainCategoryPathThrowsWithThatPath(): void
    {
        $data = $this->with(['templates', 1, 'category'], 'turystyka/namioty');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('turystyka/namioty');

        new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
    }

    public function testDuplicateCategoryPathThrows(): void
    {
        $data = $this->with(['categories', 1, 'slug'], 'bieganie');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bieganie');

        new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
    }

    /** @return iterable<string, array{list<string|int>, mixed, string}> */
    public static function invalidVariantDefinitions(): iterable
    {
        yield 'three axes' => [['templates', 0, 'variantAxes'], ['Kolor', 'Rozmiar', 'Smak'], 'variantAxes'];
        yield 'axis name not a string' => [['templates', 0, 'variantAxes'], ['Kolor', 5], 'variantAxes'];
        yield 'empty axis name' => [['templates', 0, 'variantAxes'], ['Kolor', ''], 'variantAxes'];
        yield 'fewer value lists than axes' => [['templates', 0, 'axisValues'], [['czarne', 'białe']], 'axisValues'];
        yield 'more value lists than axes' => [['templates', 1, 'axisValues'], [['żółty'], ['M']], 'axisValues'];
        yield 'empty value list' => [['templates', 0, 'axisValues', 1], [], 'axisValues'];
        yield 'empty value' => [['templates', 0, 'axisValues', 1], ['40', ''], 'axisValues'];
        yield 'value not a string' => [['templates', 0, 'axisValues', 1], ['40', 41], 'axisValues'];
        yield 'values without axes' => [['templates', 2, 'axisValues'], [['M', 'L']], 'axisValues'];
        yield 'axes without values' => [['templates', 1, 'axisValues'], [], 'axisValues'];
        yield 'value lists not a list' => [['templates', 1, 'axisValues'], 'żółty', 'axisValues'];
    }

    /**
     * @param list<string|int> $path
     */
    #[DataProvider('invalidVariantDefinitions')]
    public function testMalformedVariantDefinitionIsRejectedWithItsContext(array $path, mixed $value, string $expectedInMessage): void
    {
        $data = $this->with($path, $value);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedInMessage);

        DemoCatalogDefinition::fromArray($data);
    }

    public function testImagelessEveryOfZeroIsRejected(): void
    {
        $data = $this->with(['imagelessEvery'], 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('imagelessEvery');

        DemoCatalogDefinition::fromArray($data);
    }

    public function testMissingImagelessEveryIsRejected(): void
    {
        $data = Yaml::parseFile(self::FIXTURE);
        self::assertIsArray($data);
        unset($data['imagelessEvery']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('imagelessEvery');

        DemoCatalogDefinition::fromArray($data);
    }

    public function testCatalogWithoutAPathForAUsedPhotoCannotBeBuilt(): void
    {
        $product = $this->expand()->products[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sample.jpg');

        new DemoCatalog([], [$product], []);
    }

    public function testMissingFileIsReportedWithItsPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nie-ma-takiego.yaml');

        DemoCatalogDefinition::fromFile(__DIR__.'/nie-ma-takiego.yaml');
    }

    private function expand(): DemoCatalog
    {
        return new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURE), self::IMAGES);
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
