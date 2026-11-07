<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\OptionFacet;
use App\Catalog\Application\Search\OptionValue;
use App\Catalog\Application\Search\PriceRange;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchFacets;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchResult;
use Elastic\Elasticsearch\Client;

final readonly class ElasticsearchProductSearch implements ProductSearch
{
    /**
     * @param int $maxResultWindow the index.max_result_window of the index (Elasticsearch's default is 10 000)
     */
    public function __construct(private Client $client, private string $alias, private int $maxResultWindow = 10_000)
    {
    }

    public function search(ProductSearchCriteria $criteria): SearchResult
    {
        // Elasticsearch rejects from + size beyond the result window. A page starting at or after its end only
        // counts the matches; a page crossing it is cut at its end.
        $from = ($criteria->page - 1) * $criteria->perPage;
        [$from, $size] = $from >= $this->maxResultWindow ? [0, 0] : [$from, min($criteria->perPage, $this->maxResultWindow - $from)];

        $body = ProductSearchQuery::body($criteria, $from, $size);

        $response = ElasticsearchErrorClassifier::request('search', fn () => $this->client->search(['index' => $this->alias, 'body' => $body]))->asArray();
        $hits = $response['hits'] ?? null;
        $total = \is_array($hits) && \is_array($hits['total'] ?? null) ? ($hits['total']['value'] ?? null) : null;
        if (!\is_int($total) || !\is_array($hits['hits'] ?? null)) {
            throw new SearchIndexError('Elasticsearch returned a search response without hits.');
        }

        $items = [];
        foreach ($hits['hits'] as $hit) {
            $items[] = ProductIndexDefinition::document(\is_array($hit) ? ($hit['_source'] ?? null) : null);
        }

        return new SearchResult($items, $total, self::facets($criteria, $total, self::map($response['aggregations'] ?? null)));
    }

    /**
     * @param int                  $total        the products that meet every condition
     * @param array<string, mixed> $aggregations
     */
    private static function facets(ProductSearchCriteria $criteria, int $total, array $aggregations): SearchFacets
    {
        $categoryCounts = [];
        if ([] !== $criteria->facetCategoryIds) {
            foreach (self::buckets(self::map($aggregations['categories'] ?? null)['ids'] ?? null) as $bucket) {
                $categoryCounts[self::string($bucket['key'] ?? null)] = self::int($bucket['doc_count'] ?? null);
            }
        }

        $price = self::map(self::matching($aggregations['price'] ?? null)['min'] ?? null)['value'] ?? null;
        $max = self::map(self::matching($aggregations['price'] ?? null)['max'] ?? null)['value'] ?? null;
        // The aggregations answer in floats; the prices are whole grosze.
        $priceRange = (\is_int($price) || \is_float($price)) && (\is_int($max) || \is_float($max)) ? new PriceRange((int) $price, (int) $max) : null;

        $availableCount = self::int(self::map(self::matching($aggregations['available'] ?? null)['product'] ?? null)['doc_count'] ?? null);

        return new SearchFacets($categoryCounts, self::options($criteria, $total, $aggregations), $priceRange, $availableCount);
    }

    /**
     * The unselected axes come from one aggregation (the ten with most products, each with its values), the selected
     * ones from an aggregation each (without their own condition). Axes in the order of their products (the selected
     * ones have all `total` of them), then by name.
     *
     * @param int                  $total        the products that meet every condition
     * @param array<string, mixed> $aggregations
     *
     * @return list<OptionFacet>
     */
    private static function options(ProductSearchCriteria $criteria, int $total, array $aggregations): array
    {
        /** @var array<string, array{int, array<string, int>}> $axes axis => products, value => products */
        $axes = [];
        $free = self::map(self::map(self::matching($aggregations['free'] ?? null)['pairs'] ?? null)['axes'] ?? null);
        foreach (self::buckets($free) as $bucket) {
            $values = [];
            foreach (self::buckets($bucket['values'] ?? null) as $valueBucket) {
                $values[self::string($valueBucket['key'] ?? null)] = self::productCount($valueBucket);
            }
            $axes[self::string($bucket['key'] ?? null)] = [self::productCount($bucket), $values];
        }

        $selectedAxes = array_map(strval(...), array_keys($criteria->options));
        foreach ($selectedAxes as $position => $axis) {
            $matching = self::map(self::map(self::matching($aggregations[ProductSearchQuery::selectedAxisAggregation($position)] ?? null)['pairs'] ?? null)['axis'] ?? null);
            $values = [];
            foreach (['top', 'chosen'] as $name) {
                foreach (self::buckets($matching[$name] ?? null) as $bucket) {
                    $values[self::string($bucket['key'] ?? null)] = self::productCount($bucket);
                }
            }
            // A selected value without products stays in the list with 0.
            foreach ($criteria->options[$axis] as $value) {
                $values[$value] ??= 0;
            }
            $axes[$axis] = [$total, $values];
        }

        $order = array_map(strval(...), array_keys($axes));
        usort($order, static fn (string $a, string $b): int => $axes[$b][0] <=> $axes[$a][0] ?: strcmp($a, $b));

        $facets = [];
        foreach ($order as $axis) {
            $selected = $criteria->options[$axis] ?? [];
            $list = [];
            foreach ($axes[$axis][1] as $value => $count) {
                $list[] = new OptionValue((string) $value, $count);
            }
            usort($list, static fn (OptionValue $a, OptionValue $b): int => $b->count <=> $a->count ?: strcmp($a->value, $b->value));
            $kept = \array_slice($list, 0, ProductSearchQuery::MAX_VALUES_PER_AXIS);
            // A selected value cut by the limit is added after the kept ones, so the list may exceed the limit.
            foreach ($list as $value) {
                if (\in_array($value->value, $selected, true) && !\in_array($value, $kept, true)) {
                    $kept[] = $value;
                }
            }
            $facets[] = new OptionFacet($axis, $kept);
        }

        return $facets;
    }

    /**
     * @param array<string, mixed> $bucket a bucket with a reverse_nested sub-aggregation `product`
     */
    private static function productCount(array $bucket): int
    {
        return self::int(self::map($bucket['product'] ?? null)['doc_count'] ?? null);
    }

    /**
     * The sub-aggregations of the filter inside a nested aggregation.
     *
     * @return array<string, mixed>
     */
    private static function matching(mixed $aggregation): array
    {
        return self::map(self::map($aggregation)['matching'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buckets(mixed $aggregation): array
    {
        $buckets = self::map($aggregation)['buckets'] ?? null;
        if (!\is_array($buckets)) {
            throw new SearchIndexError('Elasticsearch returned an aggregation without buckets.');
        }

        return array_values(array_map(self::map(...), $buckets));
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new SearchIndexError('Elasticsearch returned a search response without the expected aggregations.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : throw new SearchIndexError('Elasticsearch returned a count that is not a number.');
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new SearchIndexError('Elasticsearch returned a bucket key that is not a string.');
    }
}
