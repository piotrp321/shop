<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Catalog\Application\Demo\DemoCatalog;
use App\Catalog\Domain\Product\VariantKey;
use App\Catalog\Domain\Product\VariantValues;
use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use App\Pricing\Application\Demo\DemoPromotionList;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Infrastructure\Demo\YamlDemoPromotionSource;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real file fixtures/promotions.yaml. The E2E tests rely on the prices of some demo products and
 * categories: a demo promotion must not touch them, or the test fails on a price that is not the catalog price.
 */
final class RealDemoPromotionsTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../fixtures';

    /**
     * The products the E2E tests change the price of or assert on (e2e/tests): by slug.
     * `elektrolity-w-tabletkach-lumora-nawodnienie-20` is the product of e2e/tests/promotions.spec.ts, which creates its
     * own promotion on it.
     */
    private const array E2E_PRODUCTS = [
        'hantle-regulowane-lumora-para-10',
        'buty-szosowe-tarvik-asfalt-200',
        'elektrolity-w-tabletkach-lumora-nawodnienie-20',
    ];
    /** The category whose price range e2e/tests/listing-filters.spec.ts checks, with its subcategories. */
    private const string E2E_CATEGORY = 'bieganie/buty';

    private DemoCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'), self::FIXTURES.'/images');
    }

    public function testNoDemoPromotionTouchesAnE2eProductOrCategory(): void
    {
        $promotions = new YamlDemoPromotionSource(self::FIXTURES.'/promotions.yaml')->load();

        self::assertSame([], $this->violations($promotions));
    }

    public function testEveryDemoPromotionHasVariantsWithinTheLimit(): void
    {
        $promotions = new YamlDemoPromotionSource(self::FIXTURES.'/promotions.yaml')->load();

        self::assertGreaterThanOrEqual(3, \count($promotions->promotions()));
        self::assertLessThanOrEqual(4, \count($promotions->promotions()));
        $counts = array_fill(0, \count($promotions->promotions()), 0);
        $assigned = [];
        foreach ($this->variantKeys() as [$key]) {
            foreach ($promotions->promotions() as $index => $promotion) {
                if (!isset($assigned[$key]) && $promotion->matches($key)) {
                    $assigned[$key] = true;
                    ++$counts[$index];
                }
            }
        }
        foreach ($counts as $index => $count) {
            self::assertGreaterThan(0, $count, $promotions->promotions()[$index]->name);
            self::assertLessThanOrEqual(Promotion::MAX_VARIANTS, $count, $promotions->promotions()[$index]->name);
        }
    }

    public function testThePromotionOfAnE2eProductIsAViolation(): void
    {
        foreach (self::E2E_PRODUCTS as $slug) {
            $list = new DemoPromotionList([['name' => 'Namioty', 'percentOff' => 20, 'days' => 30, 'patterns' => ['namiot-*', $slug.'/*']]]);

            self::assertNotSame([], $this->violations($list), $slug);
        }
    }

    public function testAPatternOverAWholeE2eProductTypeIsAViolation(): void
    {
        $list = new DemoPromotionList([['name' => 'Hantle', 'percentOff' => 20, 'days' => 30, 'patterns' => ['hantle-regulowane-*']]]);

        self::assertNotSame([], $this->violations($list));
    }

    public function testAPromotionOfTheCategoryOfRunningShoesIsAViolation(): void
    {
        foreach (['buty-szosowe-*', 'buty-trailowe-*-hala-500/*'] as $pattern) {
            $list = new DemoPromotionList([['name' => 'Buty', 'percentOff' => 20, 'days' => 30, 'patterns' => [$pattern]]]);

            self::assertNotSame([], $this->violations($list), $pattern);
        }
    }

    /**
     * @return list<string> the demo variants an E2E test relies on that a promotion of the list would cover
     */
    private function violations(DemoPromotionList $promotions): array
    {
        $categoryPaths = [];
        foreach ($this->catalog->categories as $category) {
            $categoryPaths[$category->id] = $category->slugPath;
        }

        $violations = [];
        foreach ($this->catalog->products as $product) {
            $paths = array_map(static fn (string $id): string => $categoryPaths[$id], [$product->mainCategoryId, ...$product->additionalCategoryIds]);
            $excluded = \in_array($product->slug, self::E2E_PRODUCTS, true)
                || [] !== array_filter($paths, static fn (string $path): bool => self::E2E_CATEGORY === $path || str_starts_with($path, self::E2E_CATEGORY.'/'));
            if (!$excluded) {
                continue;
            }
            foreach ($product->variants as $variant) {
                $key = VariantKey::of($product->slug, VariantValues::fromList($variant['values']));
                foreach ($promotions->promotions() as $promotion) {
                    if ($promotion->matches($key)) {
                        $violations[] = \sprintf('"%s" covers %s', $promotion->name, $key);
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return iterable<array{string}>
     */
    private function variantKeys(): iterable
    {
        foreach ($this->catalog->products as $product) {
            foreach ($product->variants as $variant) {
                yield [VariantKey::of($product->slug, VariantValues::fromList($variant['values']))];
            }
        }
    }
}
