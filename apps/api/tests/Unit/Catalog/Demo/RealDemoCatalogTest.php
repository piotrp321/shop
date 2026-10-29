<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Application\Demo\DemoCategory;
use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
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
        self::$catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'));
    }

    public function testExpandsToThreeHundredToFiveHundredProducts(): void
    {
        self::assertGreaterThanOrEqual(300, \count(self::$catalog->products));
        self::assertLessThanOrEqual(500, \count(self::$catalog->products));
    }

    public function testCategoryTreeIsThreeToFourLevelsDeep(): void
    {
        $depth = max(0, ...array_map(
            static fn (DemoCategory $category): int => substr_count($category->slugPath, '/') + 1,
            self::$catalog->categories,
        ));

        self::assertGreaterThanOrEqual(3, $depth);
        self::assertLessThanOrEqual(4, $depth);
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

    /** @return iterable<string, array{string}> */
    public static function reducedTaxCategories(): iterable
    {
        yield 'food' => ['food'];
        yield 'food supplement' => ['food_supplement'];
        yield 'printed publication' => ['printed_publication'];
    }

    #[DataProvider('reducedTaxCategories')]
    public function testHasAtLeastOneProductInTaxCategory(string $taxCategory): void
    {
        $matching = array_filter(
            self::$catalog->products,
            static fn ($product): bool => $taxCategory === $product->taxCategory,
        );

        self::assertNotSame([], $matching);
    }

    public function testSomeProductsHaveNoImages(): void
    {
        $imageless = array_filter(self::$catalog->products, static fn ($product): bool => [] === $product->images);

        self::assertNotSame([], $imageless);
    }
}
