<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\OptionFacet;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchFacets;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The option facets on the real Elasticsearch with many axes and values and with several selected axes, compared with
 * a brute-force model of the spec (see {@see FacetFixtures::expected()}).
 */
final class ProductSearchFacetsTest extends TestCase
{
    use UsesTemporarySearchAlias;

    protected function setUp(): void
    {
        $this->maintenance()->setup();
    }

    public function testRareValuesOfATopAxisAndTheAxisItselfSurviveManyOtherPairs(): void
    {
        $documents = FacetFixtures::manyAxes();
        $this->index($documents);

        $facets = $this->search(new ProductSearchCriteria(1, 24))->facets;

        $axes = array_map(static fn (OptionFacet $facet): string => $facet->axis, $facets->options);
        self::assertSame(['Alfa', 'B01', 'B02', 'B03', 'B04', 'B05', 'B06', 'B07', 'B08', 'B09'], $axes);
        // 80 values with one product each: the first 30 by value.
        self::assertSame(array_map(static fn (int $n): array => [\sprintf('t%02d', $n), 1], range(1, 30)), $this->pairs($facets->options[0]));
        // 40 values with two products each: the first 30 by value, on every axis.
        foreach (\array_slice($facets->options, 1) as $facet) {
            self::assertSame(array_map(static fn (int $n): array => [\sprintf('v%02d', $n), 2], range(1, 30)), $this->pairs($facet), $facet->axis);
        }
        self::assertSame(FacetFixtures::expected($documents, new ProductSearchCriteria(1, 24))['options'], $this->shape($facets));
    }

    public function testAxesAreCutByProductsNotByNestedPairs(): void
    {
        $documents = FacetFixtures::axisWithManyVariantsOnFewProducts();
        $this->index($documents);

        $facets = $this->search(new ProductSearchCriteria(1, 24))->facets;

        $axes = array_map(static fn (OptionFacet $facet): string => $facet->axis, $facets->options);
        self::assertSame(['A01', 'A02', 'A03', 'A04', 'A05', 'A06', 'A07', 'A08', 'A09', 'A10'], $axes);
        self::assertSame(FacetFixtures::expected($documents, new ProductSearchCriteria(1, 24))['options'], $this->shape($facets));
    }

    /**
     * @return iterable<string, array{ProductSearchCriteria}>
     */
    public static function criteriaOnTheValueAxis(): iterable
    {
        yield 'axis not selected' => [new ProductSearchCriteria(1, 24)];
        yield 'axis selected' => [new ProductSearchCriteria(1, 24, options: ['Rozmiar' => ['v01']])];
    }

    #[DataProvider('criteriaOnTheValueAxis')]
    public function testValuesAreCutByProductsNotByNestedPairs(ProductSearchCriteria $criteria): void
    {
        $documents = FacetFixtures::valueWithManyVariantsOnOneProduct();
        $this->index($documents);

        $facets = $this->search($criteria)->facets;

        $values = array_map(static fn (array $pair): string => $pair[0], $this->pairs($facets->options[0]));
        self::assertSame(array_map(static fn (int $n): string => \sprintf('v%02d', $n), range(1, 30)), $values);
        self::assertSame(FacetFixtures::expected($documents, $criteria)['options'], $this->shape($facets));
    }

    public function testSeveralSelectedAxesWithPriceAndAvailability(): void
    {
        $this->index(self::shoes());

        $first = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne'], 'Rozmiar' => ['42']]));

        self::assertSame(2, $first->total);
        self::assertSame([
            ['Kolor', [['czarne', 2], ['biały', 1]]],
            ['Materiał', [['skóra', 1], ['tkanina', 1]]],
            ['Rozmiar', [['42', 2], ['43', 1]]],
        ], $this->shape($first->facets));

        $second = $this->search(new ProductSearchCriteria(1, 24, availableOnly: true, priceMax: 9500, options: ['Kolor' => ['czarne'], 'Rozmiar' => ['42']]));

        self::assertSame(1, $second->total);
        self::assertSame([
            ['Kolor', [['czarne', 1]]],
            ['Materiał', [['tkanina', 1]]],
            ['Rozmiar', [['42', 1]]],
        ], $this->shape($second->facets));
        self::assertSame(1, $second->facets->availableCount);
        self::assertSame([9000, 10000], [$second->facets->price?->min, $second->facets->price?->max]);
    }

    public function testThreeSelectedAxesCountFreeAndSelectedAxesOnOneVariant(): void
    {
        $this->index(self::shoes());

        $result = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne', 'biały'], 'Rozmiar' => ['42'], 'Materiał' => ['skóra']]));

        // Variants with 42 and skóra: P1 czarne, P3 biały.
        self::assertSame(2, $result->total);
        self::assertSame([
            ['Kolor', [['biały', 1], ['czarne', 1]]],
            ['Materiał', [['skóra', 2], ['tkanina', 1]]],
            ['Rozmiar', [['42', 2], ['43', 1]]],
        ], $this->shape($result->facets));
    }

    public function testCombinationsOfDifferentVariantsDoNotCount(): void
    {
        // Black 42 and white 43 on one product: no black 43.
        $this->index([FacetFixtures::product('p1', [
            FacetFixtures::variant(['Kolor' => 'czarny', 'Rozmiar' => '42'], 100, true),
            FacetFixtures::variant(['Kolor' => 'biały', 'Rozmiar' => '43'], 100, true),
        ])]);

        $both = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarny'], 'Rozmiar' => ['43']]));
        $black = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarny']]));

        self::assertSame(0, $both->total);
        self::assertSame([
            ['Kolor', [['biały', 1], ['czarny', 0]]],
            ['Rozmiar', [['42', 1], ['43', 0]]],
        ], $this->shape($both->facets));
        // The free axis offers only what a black variant has.
        self::assertSame([['Kolor', [['biały', 1], ['czarny', 1]]], ['Rozmiar', [['42', 1]]]], $this->shape($black->facets));
    }

    /**
     * @return iterable<string, array{ProductSearchCriteria}>
     */
    public static function criteria(): iterable
    {
        yield 'none' => [new ProductSearchCriteria(1, 24)];
        yield 'one axis, two values' => [new ProductSearchCriteria(1, 24, options: ['Kolor' => ['Kolor 01', 'Kolor 02']])];
        yield 'two axes' => [new ProductSearchCriteria(1, 24, options: ['Kolor' => ['Kolor 01'], 'Rozmiar' => ['Rozmiar 01', 'Rozmiar 02', 'Rozmiar 40']])];
        yield 'three axes, price, available' => [new ProductSearchCriteria(1, 24, availableOnly: true, priceMin: 8000, priceMax: 20000, options: ['Kolor' => ['Kolor 01', 'Kolor 02'], 'Rozmiar' => ['Rozmiar 01'], 'Materiał' => ['Materiał 01', 'Materiał 03']])];
        yield 'available' => [new ProductSearchCriteria(1, 24, availableOnly: true)];
        yield 'price from' => [new ProductSearchCriteria(1, 24, priceMin: 15000)];
        yield 'price to with an axis' => [new ProductSearchCriteria(1, 24, priceMax: 12000, options: ['Wzór' => ['Wzór 02']])];
        yield 'rare value' => [new ProductSearchCriteria(1, 24, options: ['Kolor' => ['Kolor 45']])];
        yield 'a value beyond the thirtieth' => [new ProductSearchCriteria(1, 24, options: ['Waga' => ['Waga 40', 'Waga 41', 'Waga 42']])];
        yield 'absent axis' => [new ProductSearchCriteria(1, 24, options: ['Nieistnieje' => ['x'], 'Sezon' => ['Sezon 01']])];
    }

    #[DataProvider('criteria')]
    public function testFacetsOfAGeneratedSetEqualTheBruteForceModel(ProductSearchCriteria $criteria): void
    {
        $documents = FacetFixtures::random();
        $this->index($documents);

        $result = $this->search($criteria);
        $expected = FacetFixtures::expected($documents, $criteria);

        self::assertSame($expected['total'], $result->total);
        self::assertSame($expected['available'], $result->facets->availableCount);
        self::assertSame($expected['price'], null === $result->facets->price ? null : [$result->facets->price->min, $result->facets->price->max]);
        self::assertSame($expected['options'], $this->shape($result->facets));
    }

    public function testEveryValueCountEqualsTheTotalAfterAddingTheValue(): void
    {
        $documents = FacetFixtures::random();
        $this->index($documents);
        $base = new ProductSearchCriteria(1, 24, availableOnly: true, options: ['Kolor' => ['Kolor 01', 'Kolor 02']]);

        $checked = 0;
        foreach ($this->search($base)->facets->options as $facet) {
            if ('Kolor' === $facet->axis) {
                continue;
            }
            // The first, a middle and the last value of every free axis.
            foreach ([0, intdiv(\count($facet->values), 2), \count($facet->values) - 1] as $position) {
                $value = $facet->values[$position];
                $total = $this->search(new ProductSearchCriteria(1, 24, availableOnly: true, options: [...$base->options, $facet->axis => [$value->value]]))->total;
                self::assertSame($total, $value->count, $facet->axis.':'.$value->value);
                ++$checked;
            }
        }
        self::assertGreaterThan(10, $checked);
    }

    /**
     * Product 1: black 42 leather 100 zł buyable; white 43 fabric 120 zł buyable.
     * Product 2: black 43 leather 150 zł not buyable; black 42 fabric 90 zł buyable.
     * Product 3: white 42 leather 200 zł buyable. Product 4: green 44 fabric without a price.
     *
     * @return list<ProductDocument>
     */
    private static function shoes(): array
    {
        return [
            FacetFixtures::product('p1', [
                FacetFixtures::variant(['Kolor' => 'czarne', 'Rozmiar' => '42', 'Materiał' => 'skóra'], 10000, true),
                FacetFixtures::variant(['Kolor' => 'biały', 'Rozmiar' => '43', 'Materiał' => 'tkanina'], 12000, true),
            ]),
            FacetFixtures::product('p2', [
                FacetFixtures::variant(['Kolor' => 'czarne', 'Rozmiar' => '43', 'Materiał' => 'skóra'], 15000, false),
                FacetFixtures::variant(['Kolor' => 'czarne', 'Rozmiar' => '42', 'Materiał' => 'tkanina'], 9000, true),
            ]),
            FacetFixtures::product('p3', [FacetFixtures::variant(['Kolor' => 'biały', 'Rozmiar' => '42', 'Materiał' => 'skóra'], 20000, true)]),
            FacetFixtures::product('p4', [FacetFixtures::variant(['Kolor' => 'zielone', 'Rozmiar' => '44', 'Materiał' => 'tkanina'], null, false)]),
        ];
    }

    /**
     * @param list<ProductDocument> $documents
     */
    private function index(array $documents): void
    {
        $client = self::esClient();
        foreach (array_chunk(FacetFixtures::bulk($documents), 400) as $chunk) {
            $response = $client->bulk(['index' => $this->alias(), 'body' => $chunk]);
            \assert($response instanceof Elasticsearch);
            self::assertFalse($response->asArray()['errors'] ?? true, 'Bulk indexing failed.');
        }
        $client->indices()->refresh(['index' => $this->alias()]);
    }

    private function search(ProductSearchCriteria $criteria): SearchResult
    {
        return (new ElasticsearchProductSearch(self::clientFactory()->create(10.0), $this->alias()))->search($criteria);
    }

    /**
     * @return list<array{string, list<array{string, int}>}>
     */
    private function shape(SearchFacets $facets): array
    {
        return array_map(fn (OptionFacet $facet): array => [$facet->axis, $this->pairs($facet)], $facets->options);
    }

    /**
     * @return list<array{string, int}>
     */
    private function pairs(OptionFacet $facet): array
    {
        return array_map(static fn ($value): array => [$value->value, $value->count], $facet->values);
    }
}
