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
use App\Catalog\Application\Search\SearchUnavailable;
use Elastic\Elasticsearch\Client;

/**
 * A listing is one `_msearch` ({@see ProductSearchQuery}); a group of facets with more tokens than the size of terms
 * takes two more. An error of any part is the error of the whole listing (503 for a transient one, 500 otherwise),
 * never a listing with some facets missing.
 */
final readonly class ElasticsearchProductSearch implements ProductSearch
{
    /**
     * @param int $maxResultWindow the index.max_result_window of the index (Elasticsearch's default is 10 000)
     * @param int $termsSize       the size of a terms aggregation over the tokens ({@see ProductSearchQuery::TERMS_SIZE})
     */
    public function __construct(
        private Client $client,
        private string $alias,
        private int $maxResultWindow = 10_000,
        private int $termsSize = ProductSearchQuery::TERMS_SIZE,
    ) {
    }

    public function search(ProductSearchCriteria $criteria): SearchResult
    {
        // Elasticsearch rejects from + size beyond the result window. A page starting at or after its end only
        // counts the matches; a page crossing it is cut at its end.
        $from = ($criteria->page - 1) * $criteria->perPage;
        [$from, $size] = $from >= $this->maxResultWindow ? [0, 0] : [$from, min($criteria->perPage, $this->maxResultWindow - $from)];

        $query = new ProductSearchQuery($criteria, $this->termsSize);
        $responses = $this->multiSearch($query->searches($from, $size));

        $page = $responses[0];
        $hits = self::map($page['hits'] ?? null);
        $total = self::map($hits['total'] ?? null)['value'] ?? null;
        if (!\is_int($total) || !\is_array($hits['hits'] ?? null)) {
            throw new SearchIndexError('Elasticsearch returned a search response without hits.');
        }
        $items = [];
        foreach ($hits['hits'] as $hit) {
            $items[] = ProductIndexDefinition::document(\is_array($hit) ? ($hit['_source'] ?? null) : null);
        }

        $facts = [];
        foreach ($query->groups as $index => $group) {
            [$aggregations, $count] = $query->isOneSearch()
                ? self::groupOfOneSearch($page, $index)
                // A group that only counts its products has no aggregations.
                : [isset($responses[1 + $index]['aggregations']) ? self::aggregationsOf($responses[1 + $index]) : [], $group->count ? self::total($responses[1 + $index]) : null];
            $facts[$index] = self::firstFacts($group, $criteria, $aggregations, $count);
        }
        $this->countOverflows($query, $criteria, $facts);

        $prices = null;
        if ($query->priceOfProducts) {
            $prices = $query->isOneSearch()
                ? self::priceOfAggregation(self::map(self::aggregationsOf($page)['prices'] ?? null))
                : self::priceOfTopHits($responses[1 + \count($query->groups)], $responses[2 + \count($query->groups)]);
        }

        return new SearchResult($items, $total, min($total, $this->maxResultWindow), self::facets($query, $criteria, $total, $facts, $prices));
    }

    /**
     * The groups with more tokens than the size of terms, counted again: first the axes (with the categories, the
     * availability and the selected values), then the values of the axes found.
     *
     * @param array<int, array{axes: array<string, int>, values: array<string, array<string, int>>, categories: array<string, int>, available: int, count: int|null, price: array{int, int}|null, overflow: bool}> $facts
     */
    private function countOverflows(ProductSearchQuery $query, ProductSearchCriteria $criteria, array &$facts): void
    {
        $overflowing = array_keys(array_filter($facts, static fn (array $one): bool => $one['overflow']));
        if ([] === $overflowing) {
            return;
        }

        $responses = $this->multiSearch($query->axesSearches($overflowing));
        $axes = [];
        foreach ($overflowing as $position => $index) {
            $aggregations = $query->isOneSearch() ? self::groupOfOneSearch($responses[0], $index)[0] : self::aggregationsOf($responses[$position]);
            $facts[$index] = self::axesFacts($query->groups[$index], $criteria, $aggregations, $facts[$index]);
            if ($query->groups[$index]->freeAxes && [] !== $facts[$index]['axes']) {
                $axes[$index] = array_map(strval(...), array_keys($facts[$index]['axes']));
            }
        }
        if ([] === $axes) {
            return;
        }

        $responses = $this->multiSearch($query->valuesSearches($axes));
        $position = 0;
        foreach ($axes as $index => $names) {
            $aggregations = $query->isOneSearch() ? self::groupOfOneSearch($responses[0], $index)[0] : self::aggregationsOf($responses[$position]);
            $aggregations = FacetGroup::NESTED === $query->groups[$index]->kind ? self::matching($aggregations) : $aggregations;
            $one = $facts[$index];
            foreach ($names as $axisPosition => $axis) {
                $values = [];
                foreach (self::buckets($aggregations['values_'.$axisPosition] ?? null) as $bucket) {
                    $values[self::optionValue($query->groups[$index], self::string($bucket['key'] ?? null))] = self::bucketCount($query->groups[$index], $bucket);
                }
                $one['values'][$axis] = $values;
            }
            $facts[$index] = $one;
            ++$position;
        }
    }

    /**
     * @param list<array<string, mixed>> $searches
     *
     * @return list<array<string, mixed>> the response of every search, in order
     *
     * @throws SearchUnavailable when Elasticsearch or a part of the search failed transiently
     * @throws SearchIndexError  when a part of the search failed for good
     */
    private function multiSearch(array $searches): array
    {
        $body = [];
        foreach ($searches as $search) {
            $body[] = new \stdClass();
            $body[] = $search;
        }
        $response = ElasticsearchErrorClassifier::request('search', fn () => $this->client->msearch(['index' => $this->alias, 'body' => $body]))->asArray();
        $parts = $response['responses'] ?? null;
        if (!\is_array($parts) || !array_is_list($parts) || \count($parts) !== \count($searches)) {
            throw new SearchIndexError('Elasticsearch returned a multi search response without a response for every search.');
        }

        $responses = [];
        foreach ($parts as $part) {
            $part = self::map($part);
            if (isset($part['error'])) {
                $status = \is_int($part['status'] ?? null) ? $part['status'] : 500;
                $message = \sprintf('Elasticsearch rejected a part of the search: %d %s', $status, ElasticsearchErrorClassifier::describe($part['error']));

                throw ElasticsearchErrorClassifier::isTransient($status, ElasticsearchErrorClassifier::errorType($part['error'])) ? new SearchUnavailable($message) : new SearchIndexError($message);
            }
            $responses[] = $part;
        }

        return $responses;
    }

    /**
     * What a group counted in the first multi search.
     *
     * @param array<string, mixed> $aggregations
     *
     * @return array{axes: array<string, int>, values: array<string, array<string, int>>, categories: array<string, int>, available: int, count: int|null, price: array{int, int}|null, overflow: bool}
     */
    private static function firstFacts(FacetGroup $group, ProductSearchCriteria $criteria, array $aggregations, ?int $count): array
    {
        $facts = ['axes' => [], 'values' => [], 'categories' => [], 'available' => 0, 'count' => $count, 'price' => null, 'overflow' => false];

        if (FacetGroup::TOKENS === $group->kind) {
            $tokens = self::map($aggregations['tokens'] ?? null);
            $facts['overflow'] = self::isCut($tokens);
            foreach (self::buckets($tokens) as $bucket) {
                self::addToken($facts, self::readKey($group, self::string($bucket['key'] ?? null)), self::int($bucket['doc_count'] ?? null), $criteria);
            }

            return $facts;
        }

        if (isset($aggregations['pairs'])) {
            $pairs = self::map($aggregations['pairs']);
            $facts['overflow'] = self::isCut($pairs);
            foreach (self::buckets($pairs) as $bucket) {
                self::addToken($facts, self::readKey($group, self::string($bucket['key'] ?? null)), self::int($bucket['doc_count'] ?? null), $criteria);
            }
        }
        if (isset($aggregations['variants'])) {
            $matching = self::matching($aggregations);
            if (isset($matching['axes'])) {
                $facts['overflow'] = self::isCut(self::map($matching['axes']));
                foreach (self::buckets($matching['axes']) as $bucket) {
                    $facts['axes'][self::string($bucket['key'] ?? null)] = self::productCount($bucket);
                }
            }
            if (isset($matching['options'])) {
                $facts['overflow'] = $facts['overflow'] || self::isCut(self::map($matching['options']));
                foreach (self::buckets($matching['options']) as $bucket) {
                    self::addToken($facts, self::readKey($group, self::string($bucket['key'] ?? null)), self::productCount($bucket), $criteria);
                }
            }
            if ($group->price) {
                $facts['price'] = self::priceOfAggregation($matching);
            }
        }
        if (isset($aggregations['categories'])) {
            foreach (self::map(self::map($aggregations['categories'])['buckets'] ?? null) as $categoryId => $bucket) {
                $facts['categories'][(string) $categoryId] = self::int(self::map($bucket)['doc_count'] ?? null);
            }
        }

        return $facts;
    }

    /**
     * What an overflowing group counted in the second multi search: the ten axes, the categories and the availability
     * of the tokens, and the values of the selected axes; the values of the axes come with the third one.
     *
     * @param array<string, mixed>                                                                                                                                                                     $aggregations
     * @param array{axes: array<string, int>, values: array<string, array<string, int>>, categories: array<string, int>, available: int, count: int|null, price: array{int, int}|null, overflow: bool} $facts
     *
     * @return array{axes: array<string, int>, values: array<string, array<string, int>>, categories: array<string, int>, available: int, count: int|null, price: array{int, int}|null, overflow: bool}
     */
    private static function axesFacts(FacetGroup $group, ProductSearchCriteria $criteria, array $aggregations, array $facts): array
    {
        $aggregations = FacetGroup::NESTED === $group->kind ? self::matching($aggregations) : $aggregations;
        $facts['axes'] = [];
        $facts['values'] = [];
        if ($group->freeAxes) {
            foreach (self::buckets($aggregations['axes'] ?? null) as $bucket) {
                $key = self::string($bucket['key'] ?? null);
                $facts['axes'][self::axisOf($group, $key)] = self::bucketCount($group, $bucket);
            }
        }
        if (FacetGroup::TOKENS === $group->kind) {
            if ($group->categories) {
                $facts['categories'] = [];
                foreach (self::buckets($aggregations['categories'] ?? null) as $bucket) {
                    self::addToken($facts, self::readKey($group, self::string($bucket['key'] ?? null)), self::int($bucket['doc_count'] ?? null), $criteria);
                }
            }
            if ($group->availability) {
                $facts['available'] = 0;
                foreach (self::buckets($aggregations['available'] ?? null) as $bucket) {
                    $facts['available'] = self::int($bucket['doc_count'] ?? null);
                }
            }
        }
        foreach ($group->selected as $position => [$axis]) {
            foreach (['top_', 'chosen_'] as $name) {
                foreach (self::buckets($aggregations[$name.$position] ?? null) as $bucket) {
                    $facts['values'][$axis][self::optionValue($group, self::string($bucket['key'] ?? null))] = self::bucketCount($group, $bucket);
                }
            }
        }

        return $facts;
    }

    /**
     * Counts one token read from a bucket: an option, a category of the facet, an axis or the availability.
     *
     * @param array{axes: array<string, int>, values: array<string, array<string, int>>, categories: array<string, int>, available: int, count: int|null, price: array{int, int}|null, overflow: bool} $facts
     * @param array{string, string, string}                                                                                                                                                            $token the token as {@see ProductFacetTokens::read()} gives it
     */
    private static function addToken(array &$facts, array $token, int $count, ProductSearchCriteria $criteria): void
    {
        [$kind, $first, $second] = $token;
        if (ProductFacetTokens::READ_OPTION === $kind) {
            $facts['values'][$first][$second] = $count;
        } elseif (ProductFacetTokens::READ_AXIS === $kind) {
            $facts['axes'][$first] = $count;
        } elseif (ProductFacetTokens::READ_CATEGORY === $kind && \in_array($first, $criteria->facetCategoryIds, true)) {
            $facts['categories'][$first] = $count;
        } elseif (ProductFacetTokens::READ_PURCHASABLE === $kind) {
            $facts['available'] = $count;
        }
    }

    /**
     * Reads the key of a bucket of the tokens of a group: a token of `facets` or `buy_facets`, the partner of the
     * option of a group of pairs, or the plain axis or option of a nested variant.
     *
     * @return array{string, string, string}
     */
    private static function readKey(FacetGroup $group, string $key): array
    {
        if (FacetGroup::PAIRS === $group->kind) {
            [$axis, [$value]] = $group->options[0];

            return ProductFacetTokens::readPairPartner($key, $axis, $value);
        }

        return ProductFacetTokens::read($key);
    }

    /**
     * The axis of an axis bucket of a group of the second multi search: the name itself in a nested group.
     */
    private static function axisOf(FacetGroup $group, string $key): string
    {
        if (FacetGroup::NESTED === $group->kind) {
            return $key;
        }
        [$kind, $axis] = self::readKey($group, $key);

        return ProductFacetTokens::READ_AXIS === $kind ? $axis : throw new SearchIndexError('Elasticsearch returned an axis bucket that is not an axis.');
    }

    /**
     * The value of an option bucket of a group.
     */
    private static function optionValue(FacetGroup $group, string $key): string
    {
        [$kind, , $value] = self::readKey($group, $key);

        return ProductFacetTokens::READ_OPTION === $kind ? $value : throw new SearchIndexError('Elasticsearch returned an option bucket that is not an option.');
    }

    /**
     * The products of a bucket: the bucket itself for a token (once in a product), its reverse_nested count for a variant.
     *
     * @param array<string, mixed> $bucket
     */
    private static function bucketCount(FacetGroup $group, array $bucket): int
    {
        return FacetGroup::NESTED === $group->kind ? self::productCount($bucket) : self::int($bucket['doc_count'] ?? null);
    }

    /**
     * Whether a terms aggregation left buckets out.
     *
     * @param array<string, mixed> $terms
     */
    private static function isCut(array $terms): bool
    {
        return self::int($terms['sum_other_doc_count'] ?? null) > 0;
    }

    /**
     * The aggregations and the count of a group of a listing with a phrase (an aggregation of the one search).
     *
     * @param array<string, mixed> $response
     *
     * @return array{array<string, mixed>, int}
     */
    private static function groupOfOneSearch(array $response, int $index): array
    {
        $group = self::map(self::aggregationsOf($response)[ProductSearchQuery::groupName($index)] ?? null);

        return [$group, self::int($group['doc_count'] ?? null)];
    }

    /**
     * @param array<int, array{axes: array<string, int>, values: array<string, array<string, int>>, categories: array<string, int>, available: int, count: int|null, price: array{int, int}|null, overflow: bool}> $facts
     * @param array{int, int}|null                                                                                                                                                                                 $prices the price range of the buyable products, without options
     */
    private static function facets(ProductSearchQuery $query, ProductSearchCriteria $criteria, int $total, array $facts, ?array $prices): SearchFacets
    {
        $buyable = $criteria->availableOnly || null !== $criteria->priceMin || null !== $criteria->priceMax;
        $categories = [];
        $available = $buyable ? $total : 0;
        $price = $prices;
        $freeAxes = [];
        $freeValues = [];
        $selectedValues = [];
        foreach ($query->groups as $index => $group) {
            $one = $facts[$index];
            if ($group->categories) {
                $categories = array_filter($one['categories'], static fn (int $count): bool => $count > 0);
            }
            if ($group->freeAxes) {
                [$freeAxes, $freeValues] = [$one['axes'], $one['values']];
            }
            if (!$buyable && $group->availability) {
                $available = $one['available'];
            }
            if (!$buyable && $group->count) {
                $available = $one['count'] ?? 0;
            }
            if ($group->price) {
                $price = $one['price'];
            }
            foreach ($group->selected as [$axis]) {
                $selectedValues[$axis] = $one['values'][$axis] ?? [];
            }
        }
        // The order of the buckets of a terms aggregation: most products first, then by key.
        uksort($categories, static fn (string $a, string $b): int => $categories[$b] <=> $categories[$a] ?: strcmp($a, $b));

        return new SearchFacets(
            $categories,
            self::options($criteria, $total, $freeAxes, $freeValues, $selectedValues),
            null === $price ? null : new PriceRange($price[0], $price[1]),
            $available,
        );
    }

    /**
     * The ten axes without selection with most products, each with its thirty values with most products, and every
     * selected axis (with all `total` products) with its values without its own condition, the selected values always
     * listed, also with 0 and beyond the thirtieth. Axes by products, then by name; values by products, then by value.
     *
     * @param array<string, int>                $freeAxes       axis => products
     * @param array<string, array<string, int>> $freeValues     axis => value => products
     * @param array<string, array<string, int>> $selectedValues selected axis => value => products, without its own condition
     *
     * @return list<OptionFacet>
     */
    private static function options(ProductSearchCriteria $criteria, int $total, array $freeAxes, array $freeValues, array $selectedValues): array
    {
        $selected = [];
        foreach ($criteria->options as $axis => $values) {
            $selected[(string) $axis] = $values;
        }

        /** @var array<string, array{int, array<string, int>}> $axes */
        $axes = [];
        foreach ($freeAxes as $axis => $count) {
            $axis = (string) $axis;
            $values = $freeValues[$axis] ?? [];
            // An axis without values (cut off before them) is not listed.
            if (!isset($selected[$axis]) && [] !== $values) {
                $axes[$axis] = [$count, $values];
            }
        }
        $free = array_map(strval(...), array_keys($axes));
        usort($free, static fn (string $a, string $b): int => $axes[$b][0] <=> $axes[$a][0] ?: strcmp($a, $b));
        $axes = array_intersect_key($axes, array_flip(\array_slice($free, 0, ProductSearchQuery::MAX_AXES)));
        foreach ($selected as $axis => $values) {
            $counts = $selectedValues[$axis] ?? [];
            // A selected value without products stays in the list with 0.
            foreach ($values as $value) {
                $counts[$value] ??= 0;
            }
            $axes[$axis] = [$total, $counts];
        }

        $order = array_map(strval(...), array_keys($axes));
        usort($order, static fn (string $a, string $b): int => $axes[$b][0] <=> $axes[$a][0] ?: strcmp($a, $b));

        $facets = [];
        foreach ($order as $axis) {
            $chosen = $selected[$axis] ?? [];
            $list = [];
            foreach ($axes[$axis][1] as $value => $count) {
                $list[] = new OptionValue((string) $value, $count);
            }
            usort($list, static fn (OptionValue $a, OptionValue $b): int => $b->count <=> $a->count ?: strcmp($a->value, $b->value));
            $kept = \array_slice($list, 0, ProductSearchQuery::MAX_VALUES_PER_AXIS);
            // A selected value cut by the limit is added after the kept ones, so the list may exceed the limit.
            foreach (\array_slice($list, ProductSearchQuery::MAX_VALUES_PER_AXIS) as $value) {
                if (\in_array($value->value, $chosen, true)) {
                    $kept[] = $value;
                }
            }
            $facets[] = new OptionFacet($axis, $kept);
        }

        return $facets;
    }

    /**
     * @param array<string, mixed> $lowest  the response of the search of the product with the lowest "from" price
     * @param array<string, mixed> $highest the response of the search of the product with the highest price
     *
     * @return array{int, int}|null
     */
    private static function priceOfTopHits(array $lowest, array $highest): ?array
    {
        $values = [];
        foreach ([$lowest, $highest] as $response) {
            $hits = self::map($response['hits'] ?? null)['hits'] ?? null;
            if (!\is_array($hits) || !array_is_list($hits)) {
                throw new SearchIndexError('Elasticsearch returned a search response without hits.');
            }
            if ([] === $hits) {
                return null;
            }
            $sort = self::map($hits[0])['sort'] ?? null;
            if (!\is_array($sort) || !array_is_list($sort) || [] === $sort) {
                throw new SearchIndexError('Elasticsearch returned a hit without its sort value.');
            }
            $values[] = self::int($sort[0]);
        }

        return [$values[0], $values[1]];
    }

    /**
     * @param array<string, mixed> $aggregations with the aggregations `min` and `max`
     *
     * @return array{int, int}|null
     */
    private static function priceOfAggregation(array $aggregations): ?array
    {
        $min = self::map($aggregations['min'] ?? null)['value'] ?? null;
        $max = self::map($aggregations['max'] ?? null)['value'] ?? null;

        // The aggregations answer in floats; the prices are whole grosze.
        return (\is_int($min) || \is_float($min)) && (\is_int($max) || \is_float($max)) ? [(int) $min, (int) $max] : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function total(array $response): int
    {
        return self::int(self::map(self::map($response['hits'] ?? null)['total'] ?? null)['value'] ?? null);
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private static function aggregationsOf(array $response): array
    {
        return self::map($response['aggregations'] ?? null);
    }

    /**
     * @param array<string, mixed> $bucket a bucket with a reverse_nested sub-aggregation `product`
     */
    private static function productCount(array $bucket): int
    {
        return self::int(self::map($bucket['product'] ?? null)['doc_count'] ?? null);
    }

    /**
     * The sub-aggregations of the filter inside the nested aggregation of the variants.
     *
     * @param array<string, mixed> $aggregations
     *
     * @return array<string, mixed>
     */
    private static function matching(array $aggregations): array
    {
        return self::map(self::map(self::map($aggregations['variants'] ?? null)['matching'] ?? null));
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
