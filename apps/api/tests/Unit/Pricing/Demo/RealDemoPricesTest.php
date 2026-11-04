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

    public function testEveryDemoVariantHasAPrice(): void
    {
        $prices = new YamlDemoPriceSource(self::FIXTURES.'/prices.yaml')->load();
        $catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'), self::FIXTURES.'/images');

        $unpriced = [];
        $keys = 0;
        foreach ($catalog->products as $product) {
            foreach ($product->variants as $variant) {
                $key = VariantKey::of($product->slug, VariantValues::fromList($variant['values']));
                ++$keys;
                if (null === $prices->grossFor($key)) {
                    $unpriced[] = $key;
                }
            }
        }

        self::assertNotSame([], $catalog->products);
        self::assertGreaterThanOrEqual(\count($catalog->products), $keys);
        self::assertSame([], $unpriced);
    }
}
