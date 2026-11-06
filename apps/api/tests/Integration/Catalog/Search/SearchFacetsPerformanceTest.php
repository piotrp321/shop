<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Infrastructure\Search\ProductSearchQuery;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A measurement, not a check: the `took` of the facet aggregations on a generated catalog and the size of the index.
 * Skipped unless SEARCH_PERF is set, so the usual test run does not pay for it:
 *
 *   docker compose exec -e SEARCH_PERF=1 api vendor/bin/phpunit --filter SearchFacetsPerformanceTest
 *
 * SEARCH_PERF_PRODUCTS (default 5000) and SEARCH_PERF_AXES (axes per product, default 2, the limit of Catalog) tune the
 * set. The numbers go to the standard error output; they say nothing about the target scale.
 */
#[Group('performance')]
final class SearchFacetsPerformanceTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private const int REPEATS = 15;

    public function testMeasureTheFacetAggregations(): void
    {
        if (false === getenv('SEARCH_PERF')) {
            self::markTestSkipped('Set SEARCH_PERF=1 to run the measurement.');
        }
        $products = (int) (getenv('SEARCH_PERF_PRODUCTS') ?: 5000);
        $axesPerProduct = (int) (getenv('SEARCH_PERF_AXES') ?: 2);
        $this->maintenance()->setup();
        $client = self::esClient();

        $documents = self::catalog($products, $axesPerProduct);
        foreach (array_chunk(FacetFixtures::bulk($documents), 1000) as $chunk) {
            $response = $client->bulk(['index' => $this->alias(), 'body' => $chunk]);
            \assert($response instanceof Elasticsearch);
            self::assertFalse($response->asArray()['errors'] ?? true, 'Bulk indexing failed.');
        }
        $client->indices()->refresh(['index' => $this->alias()]);
        $client->indices()->forcemerge(['index' => $this->alias(), 'max_num_segments' => 1]);

        $client->indices()->flush(['index' => $this->alias()]);
        // The segment size, not store.size: this stack reports a few hundred bytes of store for a merged index.
        $segments = $client->cat()->segments(['index' => $this->alias(), 'h' => 'docs.count,size', 'format' => 'json']);
        \assert($segments instanceof Elasticsearch);
        fwrite(\STDERR, \sprintf("\n%d products, %d axes per product, Lucene documents and segment size: %s\n", $products, $axesPerProduct, json_encode($segments->asArray(), \JSON_THROW_ON_ERROR)));

        $criteria = [
            'no filters' => new ProductSearchCriteria(1, 24),
            'one axis' => new ProductSearchCriteria(1, 24, options: ['Kolor' => ['Kolor 01', 'Kolor 02']]),
            'two axes, price, available' => new ProductSearchCriteria(1, 24, availableOnly: true, priceMin: 8000, priceMax: 20000, options: ['Kolor' => ['Kolor 01'], 'Rozmiar' => ['Rozmiar 01', 'Rozmiar 02']]),
        ];
        foreach ($criteria as $label => $one) {
            $took = [];
            for ($i = 0; $i < self::REPEATS; ++$i) {
                $response = $client->search(['index' => $this->alias(), 'request_cache' => false, 'body' => ProductSearchQuery::body($one, 0, 24)]);
                \assert($response instanceof Elasticsearch);
                $ms = $response->asArray()['took'];
                \assert(\is_int($ms));
                $took[] = $ms;
            }
            sort($took);
            fwrite(\STDERR, \sprintf("  %-28s median took %d ms (min %d, max %d, %d runs)\n", $label, $took[intdiv(self::REPEATS, 2)], $took[0], $took[self::REPEATS - 1], self::REPEATS));
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Ten axes with 45 values each (skewed); a product has $axesPerProduct of them and 4-6 variants, each with a value
     * of every axis of the product.
     *
     * @return list<ProductDocument>
     */
    private static function catalog(int $count, int $axesPerProduct): array
    {
        mt_srand(20_261_107);
        $axes = ['Kolor', 'Rozmiar', 'Materiał', 'Długość', 'Waga', 'Pojemność', 'Wzór', 'Sezon', 'Płeć', 'Marka'];
        $value = static fn (string $axis): string => \sprintf('%s %02d', $axis, (int) floor(45 * (mt_rand() / mt_getrandmax()) ** 2.5) + 1);

        $products = [];
        for ($p = 0; $p < $count; ++$p) {
            shuffle($axes);
            $own = \array_slice($axes, 0, $axesPerProduct);
            $variants = [];
            for ($v = mt_rand(4, 6); $v > 0; --$v) {
                $options = [];
                foreach ($own as $axis) {
                    $options[$axis] = $value($axis);
                }
                $priced = mt_rand(1, 10) > 1;
                $variants[] = FacetFixtures::variant($options, $priced ? mt_rand(50, 300) * 100 : null, $priced && mt_rand(1, 10) <= 6);
            }
            $products[] = FacetFixtures::product(\sprintf('f%05d', $p), $variants);
        }

        return $products;
    }
}
