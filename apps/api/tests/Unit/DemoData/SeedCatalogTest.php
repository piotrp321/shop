<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Infrastructure\Demo\YamlDemoCatalogSource;
use App\DemoData\Verify\SeedCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The slugs and ids that the verification takes for the products of fixtures/catalog.yaml are the ones the seed gives
 * them (contract with the expansion of the seed catalog of Catalog).
 */
final class SeedCatalogTest extends TestCase
{
    public function testTheProductsAreTheOnesTheSeedCatalogExpandsTo(): void
    {
        $directory = \dirname(__DIR__, 3).'/fixtures';
        $expanded = new YamlDemoCatalogSource($directory.'/catalog.yaml', $directory.'/images')->load();
        $expected = [];
        foreach ($expanded->products as $product) {
            $expected[$product->slug] = $product->id;
        }

        $products = new SeedCatalog($directory.'/catalog.yaml')->products();

        self::assertGreaterThan(100, \count($products));
        self::assertSame($expected, $products);
    }

    public function testAMalformedCatalogIsReported(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'catalog');
        self::assertIsString($file);
        file_put_contents($file, "namespace: 3f6c9a52-7d1e-4b8a-9c40-5e2a1d7b6f83\ntemplates:\n  - productType: Buty\n");

        try {
            $this->expectException(\RuntimeException::class);
            new SeedCatalog($file)->products();
        } finally {
            unlink($file);
        }
    }
}
