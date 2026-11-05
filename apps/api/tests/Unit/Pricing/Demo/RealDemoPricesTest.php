<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Catalog\Domain\Product\VariantKey;
use App\Catalog\Domain\Product\VariantValues;
use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use App\Pricing\Infrastructure\Demo\YamlDemoPriceSource;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real files: every demo variant of fixtures/catalog.yaml has a price in fixtures/prices.yaml, matched
 * by its key (built by Catalog's VariantKey, like ListVariantIdentities does).
 */
final class RealDemoPricesTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../fixtures';
    /** The product types whose variants cost differently (binding, version, portion). */
    private const array PRICED_PER_VARIANT = ['Przewodnik ', 'Mapa turystyczna ', 'Danie liofilizowane '];

    /** @var array<string, int|null> variant key => gross price in grosze */
    private array $prices = [];
    /** @var array<string, list<int|null>> product name => prices of its variants in file order */
    private array $pricesOfProduct = [];

    protected function setUp(): void
    {
        $prices = new YamlDemoPriceSource(self::FIXTURES.'/prices.yaml')->load();
        $catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'), self::FIXTURES.'/images');
        foreach ($catalog->products as $product) {
            foreach ($product->variants as $variant) {
                $key = VariantKey::of($product->slug, VariantValues::fromList($variant['values']));
                $this->prices[$key] = $prices->grossFor($key);
                $this->pricesOfProduct[$product->name][] = $this->prices[$key];
            }
        }
    }

    public function testEveryDemoVariantHasAPrice(): void
    {
        self::assertGreaterThanOrEqual(1000, \count($this->prices));
        self::assertSame([], array_keys(array_filter($this->prices, static fn (?int $gross): bool => null === $gross)));
    }

    public function testVariantsOfGuidesMapsAndMealsCostDifferently(): void
    {
        $checked = 0;
        foreach ($this->pricesOfProduct as $name => $grosses) {
            if ([] === array_filter(self::PRICED_PER_VARIANT, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
                continue;
            }
            ++$checked;
            self::assertGreaterThan(1, \count($grosses), $name);
            self::assertCount(\count($grosses), array_unique($grosses), $name);
        }
        self::assertGreaterThan(0, $checked);
    }

    public function testEveryOtherProductCostsTheSameInAllItsVariants(): void
    {
        foreach ($this->pricesOfProduct as $name => $grosses) {
            if ([] !== array_filter(self::PRICED_PER_VARIANT, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
                continue;
            }
            self::assertCount(1, array_unique($grosses), $name);
        }
    }
}
