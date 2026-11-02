<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Demo;

use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use App\Inventory\Infrastructure\Demo\YamlDemoStockSource;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real files: every demo product of fixtures/catalog.yaml has a stock in fixtures/stock.yaml, and the
 * stocks are spread the way the demo and the e2e tests need.
 */
final class RealDemoStockTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../fixtures';
    /** The product the e2e tests use as the one that is out of stock. */
    private const string OUT_OF_STOCK_PRODUCT = 'kajak-turystyczny-vilmar-rzeka-2-zielony';

    /** @var array<string, int|null> slug => quantity */
    private array $quantities = [];

    protected function setUp(): void
    {
        $stock = new YamlDemoStockSource(self::FIXTURES.'/stock.yaml')->load();
        $catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'), self::FIXTURES.'/images');
        foreach ($catalog->products as $product) {
            $this->quantities[$product->slug] = $stock->quantityFor($product->slug);
        }
    }

    public function testEveryDemoProductHasAStock(): void
    {
        self::assertNotSame([], $this->quantities);
        self::assertSame([], array_keys(array_filter($this->quantities, static fn (?int $quantity): bool => null === $quantity)));
    }

    public function testMostProductsHaveTwentyToFiftyAFewHaveTwoOrThreeAndAFewHaveNone(): void
    {
        $quantities = array_values(array_filter($this->quantities, static fn (?int $quantity): bool => null !== $quantity));
        $plenty = \count(array_filter($quantities, static fn (int $quantity): bool => $quantity >= 20 && $quantity <= 50));
        $low = \count(array_filter($quantities, static fn (int $quantity): bool => $quantity >= 2 && $quantity <= 3));
        $none = \count(array_filter($quantities, static fn (int $quantity): bool => 0 === $quantity));

        self::assertGreaterThan(\count($quantities) / 2, $plenty, 'most products have 20-50 units');
        self::assertGreaterThanOrEqual(2, $low);
        self::assertLessThanOrEqual(10, $low);
        self::assertGreaterThanOrEqual(2, $none);
        self::assertLessThanOrEqual(10, $none);
        self::assertSame(\count($quantities), $plenty + $low + $none, 'no other quantities');
    }

    public function testTheProductOfTheE2eTestsIsOutOfStock(): void
    {
        self::assertArrayHasKey(self::OUT_OF_STOCK_PRODUCT, $this->quantities);
        self::assertSame(0, $this->quantities[self::OUT_OF_STOCK_PRODUCT]);
    }
}
