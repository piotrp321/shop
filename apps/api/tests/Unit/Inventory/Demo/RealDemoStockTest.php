<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Demo;

use App\Catalog\Domain\Product\VariantKey;
use App\Catalog\Domain\Product\VariantValues;
use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use App\Inventory\Infrastructure\Demo\YamlDemoStockSource;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real files: every demo variant of fixtures/catalog.yaml has a stock in fixtures/stock.yaml, and the
 * stocks are spread the way the demo and the e2e tests need.
 */
final class RealDemoStockTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../fixtures';
    /** The product the e2e tests use as the one that is out of stock: every variant has none. */
    private const string OUT_OF_STOCK_PRODUCT = 'kajak-turystyczny-vilmar-rzeka-2';
    /** The variant of it the e2e test receives into. */
    private const string RECEIVED_VARIANT = 'kajak-turystyczny-vilmar-rzeka-2/zielony';
    /** The products of the e2e price change: a default variant with stock. */
    private const string DUMBBELLS_PREFIX = 'hantle-regulowane-';

    /** @var array<string, int|null> variant key => quantity */
    private array $quantities = [];

    protected function setUp(): void
    {
        $stock = new YamlDemoStockSource(self::FIXTURES.'/stock.yaml')->load();
        $catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'), self::FIXTURES.'/images');
        foreach ($catalog->products as $product) {
            foreach ($product->variants as $variant) {
                $key = VariantKey::of($product->slug, VariantValues::fromList($variant['values']));
                $this->quantities[$key] = $stock->quantityFor($key);
            }
        }
    }

    public function testEveryDemoVariantHasAStock(): void
    {
        self::assertNotSame([], $this->quantities);
        self::assertSame([], array_keys(array_filter($this->quantities, static fn (?int $quantity): bool => null === $quantity)));
    }

    public function testMostVariantsHaveTwentyToFiftyAFewHaveTwoOrThreeAndAFewHaveNone(): void
    {
        $quantities = array_values(array_filter($this->quantities, static fn (?int $quantity): bool => null !== $quantity));
        $plenty = \count(array_filter($quantities, static fn (int $quantity): bool => $quantity >= 20 && $quantity <= 50));
        $low = \count(array_filter($quantities, static fn (int $quantity): bool => $quantity >= 2 && $quantity <= 3));
        $none = \count(array_filter($quantities, static fn (int $quantity): bool => 0 === $quantity));

        self::assertGreaterThan(\count($quantities) / 2, $plenty, 'most variants have 20-50 units');
        self::assertGreaterThanOrEqual(2, $low);
        self::assertLessThanOrEqual(15, $low);
        self::assertGreaterThanOrEqual(2, $none);
        self::assertLessThanOrEqual(15, $none);
        self::assertSame(\count($quantities), $plenty + $low + $none, 'no other quantities');
    }

    public function testEveryVariantOfTheKayakOfTheE2eTestsIsOutOfStock(): void
    {
        $variants = $this->variantsOf(self::OUT_OF_STOCK_PRODUCT);

        self::assertGreaterThanOrEqual(2, \count($variants));
        self::assertArrayHasKey(self::RECEIVED_VARIANT, $variants);
        self::assertSame([0], array_values(array_unique($variants)));
    }

    public function testADumbbellsProductHasStockInItsDefaultVariant(): void
    {
        $dumbbells = array_filter($this->quantities, static fn (?int $quantity, string $key): bool => str_starts_with($key, self::DUMBBELLS_PREFIX), \ARRAY_FILTER_USE_BOTH);

        self::assertNotSame([], $dumbbells);
        foreach (array_keys($dumbbells) as $key) {
            self::assertStringEndsWith('/standard', $key);
        }
        self::assertNotSame([], array_filter($dumbbells, static fn (?int $quantity): bool => null !== $quantity && $quantity > 0));
    }

    public function testAnotherProductHasVariantsWithAndWithoutStock(): void
    {
        $mixed = [];
        foreach ($this->quantities as $key => $quantity) {
            $mixed[explode('/', $key)[0]][] = $quantity;
        }
        $mixed = array_filter(
            $mixed,
            static fn (array $quantities, string $product): bool => self::OUT_OF_STOCK_PRODUCT !== $product
                && \in_array(0, $quantities, true)
                && [] !== array_filter($quantities, static fn (?int $quantity): bool => null !== $quantity && $quantity > 0),
            \ARRAY_FILTER_USE_BOTH,
        );

        self::assertNotSame([], $mixed);
    }

    /**
     * @return array<string, int|null> the variants of the product with their quantities
     */
    private function variantsOf(string $productSlug): array
    {
        return array_filter($this->quantities, static fn (?int $quantity, string $key): bool => str_starts_with($key, $productSlug.'/'), \ARRAY_FILTER_USE_BOTH);
    }
}
