<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
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

    public function testExpandsToThreeHundredToFiveHundredProducts(): void
    {
        self::assertGreaterThanOrEqual(300, \count(self::$catalog->products));
        self::assertLessThanOrEqual(500, \count(self::$catalog->products));
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
