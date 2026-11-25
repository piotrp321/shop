<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A regression test of the rows the generator wrote before the content fields (brand, description, parameters, SKU,
 * EAN): the digest of the columns that existed then, for the profile small with the seed of profiles.yaml and the
 * reference moment of the tests. The digests were computed on the code before the generator got the new fields, so a
 * change of the generator that moves an id, a slug, a name, a short description, a variant or a price fails here
 * (docs/features/product-data/spec.md, criterion 14).
 */
final class GeneratedCatalogDigestTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    /** The columns of catalog_product and catalog_product_variant of the catalog step, one line per row. */
    private const string PRODUCTS_AND_VARIANTS = '9796332ebde609fa8987d2c8412d6d90';
    /** The columns of pricing_variant_price and pricing_price_history of the prices step, one line per row. */
    private const string PRICES = 'e2124bfba0d358e0932ca0e1842fa3c4';

    protected function setUp(): void
    {
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')), 'shop_test has catalog rows that are not the ones of this test.');
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM pricing_variant_price')), 'shop_test has price rows that are not the ones of this test.');
    }

    public function testTheIdsSlugsNamesShortDescriptionsAndVariantsAreTheOnesOfBeforeTheContentFields(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');

        $connection = $this->connection();
        self::assertSame(2_000, $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product')));
        $products = $this->text($connection->fetchOne("SELECT md5(string_agg(id || '|' || slug || '|' || name || '|' || short_description, E'\\n' ORDER BY id)) FROM catalog_product"));
        $variants = $this->text($connection->fetchOne("SELECT md5(string_agg(id || '|' || product_id || '|' || position || '|' || option_values::text || '|' || values_key, E'\\n' ORDER BY id)) FROM catalog_product_variant"));

        self::assertSame(self::PRODUCTS_AND_VARIANTS, md5($products.'|'.$variants), 'products '.$products.', variants '.$variants);
    }

    public function testThePricesAndTheirHistoryAreTheOnesOfBeforeTheContentFields(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');
        $this->succeeds('demo-data:prices');

        $connection = $this->connection();
        $prices = $this->text($connection->fetchOne("SELECT md5(string_agg(t::text, E'\\n' ORDER BY t.variant_id)) FROM pricing_variant_price t"));
        $history = $this->text($connection->fetchOne("SELECT md5(string_agg(t::text, E'\\n' ORDER BY t.id)) FROM pricing_price_history t"));

        self::assertSame(self::PRICES, md5($prices.'|'.$history), 'prices '.$prices.', history '.$history);
    }
}
