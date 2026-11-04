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
    /** The variant the e2e tests use as the one that is out of stock (the default variant of its product). */
    private const string OUT_OF_STOCK_VARIANT = 'kajak-turystyczny-vilmar-rzeka-2-zielony/standard';

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
        self::assertLessThanOrEqual(10, $low);
        self::assertGreaterThanOrEqual(2, $none);
        self::assertLessThanOrEqual(10, $none);
        self::assertSame(\count($quantities), $plenty + $low + $none, 'no other quantities');
    }

    public function testTheVariantOfTheE2eTestsIsOutOfStock(): void
    {
        self::assertArrayHasKey(self::OUT_OF_STOCK_VARIANT, $this->quantities);
        self::assertSame(0, $this->quantities[self::OUT_OF_STOCK_VARIANT]);
    }
}
