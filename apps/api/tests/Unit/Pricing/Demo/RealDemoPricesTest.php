<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Catalog\Infrastructure\Demo\DemoCatalogDefinition;
use App\Catalog\Infrastructure\Demo\DemoCatalogExpander;
use App\Pricing\Infrastructure\Demo\YamlDemoPriceSource;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real files: every demo product of fixtures/catalog.yaml has a price in fixtures/prices.yaml.
 */
final class RealDemoPricesTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../fixtures';

    public function testEveryDemoProductHasAPrice(): void
    {
        $prices = new YamlDemoPriceSource(self::FIXTURES.'/prices.yaml')->load();
        $catalog = new DemoCatalogExpander()->expand(DemoCatalogDefinition::fromFile(self::FIXTURES.'/catalog.yaml'), self::FIXTURES.'/images');

        $unpriced = [];
        foreach ($catalog->products as $product) {
            if (null === $prices->grossFor($product->slug)) {
                $unpriced[] = $product->slug;
            }
        }

        self::assertNotSame([], $catalog->products);
        self::assertSame([], $unpriced);
    }
}
