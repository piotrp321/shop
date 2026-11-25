<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoProduct;
use App\Catalog\Domain\Product\Ean;
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
            self::withoutCodes($product->variants),
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
            self::withoutCodes($product->variants),
        );
    }

    public function testProductWithoutAxesHasOneDefaultVariantWithAnIdOfItsOwn(): void
    {
        $product = $this->expand()->products[8];
        $namespace = Uuid::fromString(self::NAMESPACE);

        self::assertSame([], $product->variantAxes);
        self::assertSame(
            [['id' => Uuid::v5($namespace, 'variant:hantle-lumora-para-10/standard')->toRfc4122(), 'values' => []]],
            self::withoutCodes($product->variants),
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

    public function testBrandIsTheBrandOfTheNameOfEachProduct(): void
    {
        $products = $this->expand()->products;

        self::assertSame('Tarvik', $products[0]->brand);
        self::assertSame('Norvane', $products[3]->brand);
        self::assertSame('Tarvik', $products[6]->brand);
        self::assertSame('Dravik', $products[9]->brand);
    }

    public function testDescriptionIsTheSentenceOfTheModelThenTheParagraphsOfTheTypeSeparatedByBlankLines(): void
    {
        $products = $this->expand()->products;

        self::assertSame(
            "Lekkie buty na asfalt.\n\nButy do biegania po utwardzonych drogach.\n\nPodeszwa z pianki EVA tłumi wstrząsy.",
            $products[0]->description,
        );
        // Another model of the type gets the same paragraphs after its own sentence.
        self::assertSame(
            "Buty na szybkie treningi.\n\nButy do biegania po utwardzonych drogach.\n\nPodeszwa z pianki EVA tłumi wstrząsy.",
            $products[2]->description,
        );
    }

    public function testModelWithAFullDescriptionTakesItInsteadOfTheSentenceAndTheParagraphsOfTheType(): void
    {
        self::assertSame("Własny opis modelu Dystans 400.\n\nDrugi akapit własnego opisu.", $this->expand()->products[1]->description);
    }

    public function testTypeWithoutParagraphsGivesTheSentenceOfTheModelAsTheDescription(): void
    {
        self::assertSame('Plecak dzienny.', $this->expand()->products[6]->description);
    }

    public function testParametersAreThoseOfTheTypeAndAParameterOfTheModelWithTheSameNameReplacesItInPlace(): void
    {
        $products = $this->expand()->products;

        self::assertSame(
            [
                ['name' => 'Materiał cholewki', 'value' => 'siatka'],
                ['name' => 'Waga', 'value' => '250 g'],
                ['name' => 'Drop', 'value' => '8 mm'],
            ],
            $products[0]->parameters,
        );
        // A model without parameters of its own has those of the type, and a type without any gives none.
        self::assertSame(
            [['name' => 'Materiał cholewki', 'value' => 'siatka'], ['name' => 'Waga', 'value' => '280 g']],
            $products[1]->parameters,
        );
        self::assertSame([], $products[6]->parameters);
    }

    public function testParameterOfTheModelReplacesTheOneOfTheTypeWhateverTheCaseOfItsName(): void
    {
        $data = $this->with(['templates', 0, 'models', 0, 'parameters'], [['name' => 'WAGA', 'value' => '250 g']]);

        $parameters = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES)->products[0]->parameters;

        self::assertSame([['name' => 'Materiał cholewki', 'value' => 'siatka'], ['name' => 'WAGA', 'value' => '250 g']], $parameters);
    }

    public function testNameRepeatedInTheParametersOfOneModelIsNotMergedAway(): void
    {
        $data = $this->with(['templates', 0, 'models', 0, 'parameters'], [['name' => 'Waga', 'value' => '250 g'], ['name' => 'waga', 'value' => '260 g']]);

        $parameters = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES)->products[0]->parameters;

        self::assertSame(['Materiał cholewki', 'Waga', 'waga'], array_column($parameters, 'name'));
    }

    public function testSkuIsTheLettersOfTheBrandThenTheModelThenTheValuesOfTheVariant(): void
    {
        $products = $this->expand()->products;

        self::assertSame(
            ['TAR-ASFALT-200-CZA-40', 'TAR-ASFALT-200-CZA-41', 'TAR-ASFALT-200-BIA-40', 'TAR-ASFALT-200-BIA-41'],
            array_column($products[0]->variants, 'sku'),
        );
        self::assertSame(['TAR-ZAB-20-ZOL', 'TAR-ZAB-20-ZIE'], array_column($products[6]->variants, 'sku'));
        // A variant without values has the code of the product only.
        self::assertSame(['LUM-PARA-10'], array_column($products[8]->variants, 'sku'));
        self::assertSame(['DRA-PARA-10'], array_column($products[9]->variants, 'sku'));
    }

    public function testEveryWordOfAMultiWordBrandTakesItsFirstThreeLetters(): void
    {
        $data = $this->with(['templates', 1, 'brands'], ['Tarvik Press']);

        $products = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES)->products;

        self::assertSame('Tarvik Press', $products[6]->brand);
        self::assertSame(['TAR-PRE-ZAB-20-ZOL', 'TAR-PRE-ZAB-20-ZIE'], array_column($products[6]->variants, 'sku'));
    }

    public function testSkuOfTheWholeCatalogIsUniqueAndStartsWithALetter(): void
    {
        $skus = [];
        foreach ($this->expand()->products as $product) {
            foreach ($product->variants as $variant) {
                $skus[] = $variant['sku'];
            }
        }

        self::assertCount(4 * 6 + 2 * 2 + 2, $skus);
        self::assertSame($skus, array_values(array_unique($skus)));
        foreach ($skus as $sku) {
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9\-_.\/]{0,39}$/D', $sku);
        }
    }

    public function testTwoProductsWithTheSameSkuAreRejectedWithThatSku(): void
    {
        // Both brands give the letters TAR, the model and the values are the same.
        $data = $this->with(['templates', 0, 'brands'], ['Tarvik', 'Tarwik']);

        try {
            new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES);
            self::fail('A repeated SKU must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('SKU "TAR-ASFALT-200-CZA-40"', $e->getMessage());
            self::assertStringContainsString('buty-tarwik-asfalt-200', $e->getMessage());
        }
    }

    public function testEanIsAnEan13OfTheRestrictedCirculationPrefixTwoWithAValidCheckDigit(): void
    {
        foreach ($this->expand()->products as $product) {
            foreach ($product->variants as $variant) {
                self::assertMatchesRegularExpression('/^2\d{12}$/D', $variant['ean'], $product->name);
                $ean = Ean::optional($variant['ean']);
                self::assertNotNull($ean, $product->name);
                // The prefix 2 is a number of restricted circulation, so the shop has no gtin for it.
                self::assertSame('', $ean->gtin(), $product->name);
            }
        }
    }

    public function testEanComesFromTheKeyOfTheVariantNotFromItsPositionInTheCatalog(): void
    {
        $all = $this->expand()->products;
        $data = Yaml::parseFile(self::FIXTURE);
        self::assertIsArray($data);
        self::assertIsArray($data['templates']);
        // Without the first template every other product moves up in the expansion.
        $data['templates'] = \array_slice($data['templates'], 1);
        $withoutFirst = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromArray($data), self::IMAGES)->products;

        self::assertSame($all[6]->name, $withoutFirst[0]->name);
        self::assertSame(array_column($all[6]->variants, 'ean'), array_column($withoutFirst[0]->variants, 'ean'));
        // Different variants have different codes.
        $eans = [];
        foreach ($all as $product) {
            array_push($eans, ...array_column($product->variants, 'ean'));
        }
        self::assertSame($eans, array_values(array_unique($eans)));
    }

    /**
     * @param list<array<string, mixed>> $variants
     *
     * @return list<array{id: mixed, values: mixed}>
     */
    private static function withoutCodes(array $variants): array
    {
        return array_map(static fn (array $variant): array => ['id' => $variant['id'], 'values' => $variant['values']], $variants);
    }

    /** @return iterable<string, array{list<string|int>, mixed, string}> */
    public static function invalidDescriptionAndParameterDefinitions(): iterable
    {
        yield 'description not a list' => [['templates', 0, 'description'], 'Jeden akapit.', '"description"'];
        yield 'description with an empty paragraph' => [['templates', 0, 'description'], ['Akapit.', ''], '"description"'];
        yield 'parameters not a list' => [['templates', 0, 'parameters'], 'Waga', '"parameters"'];
        yield 'parameter not a map' => [['templates', 0, 'parameters'], ['Waga'], 'parameter'];
        yield 'parameter without a value' => [['templates', 0, 'parameters'], [['name' => 'Waga']], '"value"'];
        yield 'parameter with an empty name' => [['templates', 0, 'parameters'], [['name' => '', 'value' => '1']], '"name"'];
        yield 'parameter of a model without a name' => [['templates', 0, 'models', 0, 'parameters'], [['value' => '1']], '"name"'];
        yield 'full description not a list' => [['templates', 0, 'models', 1, 'fullDescription'], 'Opis', '"fullDescription"'];
        yield 'empty full description' => [['templates', 0, 'models', 1, 'fullDescription'], [], '"fullDescription"'];
    }

    /**
     * @param list<string|int> $path
     */
    #[DataProvider('invalidDescriptionAndParameterDefinitions')]
    public function testMalformedDescriptionOrParametersAreRejectedWithTheirContext(array $path, mixed $value, string $expectedInMessage): void
    {
        $data = $this->with($path, $value);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedInMessage);

        DemoCatalogDefinition::fromArray($data);
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
