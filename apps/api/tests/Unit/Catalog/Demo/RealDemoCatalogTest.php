<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoProduct;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use App\Catalog\Infrastructure\Demo\YamlDemoCatalogSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real definition (apps/api/fixtures/catalog.yaml) against edits that break the demo data.
 */
final class RealDemoCatalogTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../fixtures';

    private static DemoCatalog $catalog;

    public static function setUpBeforeClass(): void
    {
        self::$catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'), self::FIXTURES.'/images');
    }

    public function testProductIsABrandTimesModelWithAboutOneThousandVariants(): void
    {
        self::assertCount(191, self::$catalog->products);

        $variants = array_sum(array_map(static fn (DemoProduct $product): int => \count($product->variants), self::$catalog->products));
        self::assertGreaterThanOrEqual(1000, $variants);
        self::assertLessThanOrEqual(1200, $variants);
        foreach (self::$catalog->products as $product) {
            self::assertLessThanOrEqual(21, \count($product->variants), $product->name);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, list<list<string>>}> the product type, the axes and the values of each axis
     */
    public static function axesOfTypes(): iterable
    {
        $shoeSizes = ['40', '41', '42', '43', '44', '45', '46'];
        $clothesSizes = ['S', 'M', 'L', 'XL'];
        $helmetSizes = ['S', 'M', 'L'];

        yield 'road shoes' => ['Buty szosowe', ['Kolor', 'Rozmiar'], [['czarne', 'niebieskie'], $shoeSizes]];
        yield 'trail shoes' => ['Buty trailowe', ['Kolor', 'Rozmiar'], [['czarne', 'zielone', 'pomarańczowe'], $shoeSizes]];
        yield 'football boots' => ['Buty piłkarskie', ['Kolor', 'Rozmiar'], [['czarne', 'niebieskie'], $shoeSizes]];
        yield 'running jacket' => ['Kurtka biegowa', ['Kolor', 'Rozmiar'], [['czarna', 'granatowa', 'limonkowa'], $clothesSizes]];
        yield 'running shirt' => ['Koszulka biegowa', ['Kolor', 'Rozmiar'], [['biała', 'szara', 'turkusowa'], $clothesSizes]];
        yield 'ski jacket' => ['Kurtka narciarska', ['Kolor', 'Rozmiar'], [['czerwona', 'granatowa', 'czarna'], $clothesSizes]];
        yield 'wetsuit' => ['Pianka pływacka', ['Kolor', 'Rozmiar'], [['czarna', 'grafitowa'], $clothesSizes]];
        yield 'mountain bike' => ['Rower górski', ['Kolor', 'Rozmiar ramy'], [['grafitowy', 'zielony', 'pomarańczowy'], $helmetSizes]];
        yield 'electric bike' => ['Rower elektryczny', ['Kolor', 'Rozmiar ramy'], [['czarny', 'grafitowy', 'niebieski'], $helmetSizes]];
        yield 'bike helmet' => ['Kask rowerowy', ['Kolor', 'Rozmiar'], [['czarny', 'biały', 'czerwony'], $helmetSizes]];
        yield 'multisport helmet' => ['Kask wielosportowy', ['Kolor', 'Rozmiar'], [['czarny', 'biały', 'niebieski'], $helmetSizes]];
        yield 'downhill skis' => ['Narty zjazdowe', ['Kolor', 'Długość'], [['grafitowe', 'czerwone'], ['160 cm', '170 cm', '180 cm']]];
        yield 'touring skis' => ['Narty skiturowe', ['Kolor', 'Długość'], [['niebieskie', 'pomarańczowe'], ['164 cm', '172 cm', '180 cm']]];
        yield 'guide' => ['Przewodnik', ['Oprawa'], [['oprawa miękka', 'oprawa twarda']]];
        yield 'map' => ['Mapa turystyczna', ['Wersja'], [['składana', 'laminowana']]];
        yield 'freeze-dried meal' => ['Danie liofilizowane', ['Porcja'], [['jednoosobowa', 'dwuosobowa']]];
        yield 'energy bar' => ['Baton energetyczny', ['Smak'], [['czekoladowy', 'orzechowy', 'owocowy']]];
        yield 'energy gel' => ['Żel energetyczny', ['Smak'], [['cytrynowy', 'malinowy', 'pomarańczowy']]];
        yield 'protein powder' => ['Odżywka białkowa', ['Smak'], [['czekoladowa', 'waniliowa', 'truskawkowa']]];
        yield 'electrolytes' => ['Elektrolity w tabletkach', ['Smak'], [['cytrynowe', 'pomarańczowe']]];
        yield 'adjustable dumbbells' => ['Hantle regulowane', [], []];
        yield 'running backpack' => ['Plecak biegowy', ['Kolor'], [['czarny', 'pomarańczowy']]];
        yield 'kayak' => ['Kajak turystyczny', ['Kolor'], [['pomarańczowy', 'zielony']]];
        yield 'volleyball' => ['Piłka siatkowa', ['Kolor'], [['biało-niebieska', 'żółto-niebieska']]];
    }

    /**
     * @param list<string>       $axes
     * @param list<list<string>> $axisValues
     */
    #[DataProvider('axesOfTypes')]
    public function testProductTypeHasItsAxesAndTheProductOfTheirValuesInFileOrder(string $type, array $axes, array $axisValues): void
    {
        $group = array_values(array_filter(self::$catalog->products, static fn (DemoProduct $product): bool => str_starts_with($product->name, $type.' ')));
        self::assertNotSame([], $group, $type);

        $expected = [[]];
        foreach ($axisValues as $values) {
            $expected = array_merge(...array_map(static fn (array $combination): array => array_map(static fn (string $value): array => [...$combination, $value], $values), $expected));
        }
        foreach ($group as $product) {
            self::assertSame($axes, $product->variantAxes, $product->name);
            self::assertSame($expected, array_column($product->variants, 'values'), $product->name);
        }
    }

    public function testEveryOtherProductTypeVariesByColourOnly(): void
    {
        $typed = [];
        foreach (self::axesOfTypes() as [$type]) {
            $typed[] = $type.' ';
        }

        $others = array_filter(
            self::$catalog->products,
            static fn (DemoProduct $product): bool => [] === array_filter($typed, static fn (string $prefix): bool => str_starts_with($product->name, $prefix)),
        );
        self::assertNotSame([], $others);
        foreach ($others as $product) {
            self::assertSame(['Kolor'], $product->variantAxes, $product->name);
            self::assertGreaterThanOrEqual(2, \count($product->variants), $product->name);
            self::assertLessThanOrEqual(3, \count($product->variants), $product->name);
        }
    }

    public function testNeitherNameNorSlugHoldsAVariantValue(): void
    {
        foreach (self::$catalog->products as $product) {
            foreach ($product->variants as $variant) {
                foreach ($variant['values'] as $value) {
                    // Sizes such as "S" or "40" are too short and too common to look for in a text.
                    if (mb_strlen($value) < 4) {
                        continue;
                    }
                    self::assertStringNotContainsString(mb_strtolower($value), mb_strtolower($product->name), $product->name);
                    self::assertStringNotContainsString(Slug::slugify($value), $product->slug, $product->name);
                }
            }
        }
    }

    public function testSomeProductsHaveTheDefaultVariantOnly(): void
    {
        $dumbbells = array_filter(self::$catalog->products, static fn (DemoProduct $product): bool => str_starts_with($product->name, 'Hantle regulowane '));

        self::assertCount(4, $dumbbells);
        foreach ($dumbbells as $product) {
            self::assertSame([], $product->variantAxes);
            self::assertCount(1, $product->variants);
            self::assertSame([], $product->variants[0]['values']);
        }
    }

    public function testEveryTopLevelBranchIsThreeToFourLevelsDeep(): void
    {
        $depthByRoot = [];
        foreach (self::$catalog->categories as $category) {
            $root = explode('/', $category->slugPath)[0];
            $depthByRoot[$root] = max($depthByRoot[$root] ?? 0, substr_count($category->slugPath, '/') + 1);
        }

        self::assertCount(7, $depthByRoot);
        foreach ($depthByRoot as $root => $depth) {
            self::assertGreaterThanOrEqual(3, $depth, $root);
            self::assertLessThanOrEqual(4, $depth, $root);
        }
    }

    public function testSourceAcceptsTheRealDefinitionAndPhotos(): void
    {
        $catalog = new YamlDemoCatalogSource(self::FIXTURES.'/catalog.yaml', self::FIXTURES.'/images')->load();

        self::assertCount(\count(self::$catalog->products), $catalog->products);
    }

    public function testEveryPooledImageFileExists(): void
    {
        $files = [];
        foreach (self::$catalog->products as $product) {
            foreach ($product->images as $image) {
                $files[$image['file']] = true;
            }
        }

        self::assertNotSame([], $files);
        foreach (array_keys($files) as $file) {
            self::assertFileExists(self::FIXTURES.'/images/'.$file);
        }
    }

    /** @return iterable<string, array{TaxClassCode}> */
    public static function taxClasses(): iterable
    {
        foreach (TaxClassCode::cases() as $class) {
            yield $class->value => [$class];
        }
    }

    #[DataProvider('taxClasses')]
    public function testHasAtLeastOneProductInTaxClass(TaxClassCode $class): void
    {
        $matching = array_filter(
            self::$catalog->products,
            static fn ($product): bool => $class->value === $product->taxClass,
        );

        self::assertNotSame([], $matching);
    }

    /** @return iterable<string, array{string, string, TaxClassCode, list<string>}> */
    public static function classedGroups(): iterable
    {
        yield 'guides' => ['turystyka/nawigacja/przewodniki', 'Przewodnik ', TaxClassCode::PrintedBook, []];
        yield 'maps' => ['turystyka/nawigacja/mapy', 'Mapa turystyczna ', TaxClassCode::PrintedMap, []];
        yield 'freeze-dried meals' => ['turystyka/zywnosc', 'Danie liofilizowane ', TaxClassCode::FreezeDriedMeal, []];
        yield 'energy bars' => ['fitness/odzywki/zywnosc-sportowa', 'Baton energetyczny ', TaxClassCode::EnergyBar, ['Owsiany', 'Daktylowy', 'Proteinowy']];
        yield 'energy gels' => ['fitness/odzywki/zywnosc-sportowa', 'Żel energetyczny ', TaxClassCode::EnergyGel, ['Start', 'Dystans']];
        yield 'protein powders' => ['fitness/odzywki/suplementy', 'Odżywka białkowa ', TaxClassCode::ProteinPowder, ['Serwatka', 'Roślinna']];
        yield 'electrolytes' => ['fitness/odzywki/suplementy', 'Elektrolity w tabletkach ', TaxClassCode::ElectrolyteTablet, ['Nawodnienie']];
    }

    /**
     * @param list<string> $models model names the group must contain; empty means any
     */
    #[DataProvider('classedGroups')]
    public function testGroupHasItsTaxClass(string $categoryPath, string $namePrefix, TaxClassCode $class, array $models): void
    {
        $categoryIds = [];
        foreach (self::$catalog->categories as $category) {
            $categoryIds[$category->id] = $category->slugPath;
        }

        $group = array_filter(
            self::$catalog->products,
            static fn ($product): bool => str_starts_with($product->name, $namePrefix),
        );

        self::assertNotSame([], $group, $namePrefix);
        foreach ($group as $product) {
            self::assertSame($categoryPath, $categoryIds[$product->mainCategoryId], $product->name);
            self::assertSame($class->value, $product->taxClass, $product->name);
        }
        foreach ($models as $model) {
            self::assertNotSame([], array_filter($group, static fn ($product): bool => str_contains($product->name, $model)), $model);
        }
    }

    public function testEveryProductOutsideTheClassifiedGroupsIsStandard(): void
    {
        $prefixes = [];
        foreach (self::classedGroups() as [, $prefix]) {
            $prefixes[] = $prefix;
        }

        foreach (self::$catalog->products as $product) {
            if ([] !== array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($product->name, $prefix))) {
                continue;
            }
            self::assertSame('standard', $product->taxClass, $product->name);
        }
    }

    public function testSomeProductsHaveNoImages(): void
    {
        $imageless = array_filter(self::$catalog->products, static fn ($product): bool => [] === $product->images);

        self::assertNotSame([], $imageless);
    }
}
