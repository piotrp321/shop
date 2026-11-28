<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\ProductSort;
use App\Catalog\Domain\Product\ProductStatus;

/**
 * The searches of a listing in Elasticsearch (spec search, M1, M2, "Zapytanie", "Fasety"): the page of results with
 * every condition in its query, and the facets grouped by their conditions on a variant, each group one search of size
 * 0 with the conditions in its query ({@see FacetGroup}); a listing without options takes its price range from two
 * searches of one product. A listing with a phrase is one search, so the text is matched once: the conditions of the
 * results in `post_filter`, every group a `filter` aggregation.
 *
 * The conditions on a variant are queries on the fields of the product (M1): a variant has at most two options and no
 * two variants of a product have the same ones, so "a variant with these options" is a token of the product, and only
 * a price together with an option needs the nested variants.
 *
 * A group whose terms aggregation over the tokens has more buckets than its size is counted exactly by two more
 * searches: the axes (with the categories, the availability and the values of the selected axes), then the values of
 * every axis found ({@see axesSearches()}, {@see valuesSearches()}).
 *
 * The status is in every query, so no search ever sees a draft.
 */
final readonly class ProductSearchQuery
{
    public const int MAX_AXES = 10;
    public const int MAX_VALUES_PER_AXIS = 30;
    /** The size of a terms aggregation over the tokens: on one shard every bucket when there are at most this many. */
    public const int TERMS_SIZE = 10_000;

    private const string VARIANTS = 'variants';
    /** Most products first (the reverse_nested count, not the nested documents), then by key. */
    private const array BY_PRODUCTS = [['product' => 'desc'], ['_key' => 'asc']];
    private const array BY_COUNT = [['_count' => 'desc'], ['_key' => 'asc']];

    /** @var list<FacetGroup> */
    public array $groups;

    /** Whether the price range is the lowest "from" price and the highest price of the buyable products (no option). */
    public bool $priceOfProducts;

    /**
     * @param int $termsSize the size of a terms aggregation over the tokens; smaller only in tests, to count every
     *                       group the way of a listing with more distinct tokens
     */
    public function __construct(private ProductSearchCriteria $criteria, private int $termsSize = self::TERMS_SIZE)
    {
        if ($termsSize < 1) {
            throw new \InvalidArgumentException(\sprintf('The size of a terms aggregation must be at least 1, got %d.', $termsSize));
        }
        $this->groups = self::plan($criteria);
        $this->priceOfProducts = [] === $criteria->options;
    }

    /**
     * Whether the listing is one search (with a phrase): the groups are aggregations of it, named by {@see groupName()}.
     */
    public function isOneSearch(): bool
    {
        return null !== $this->criteria->phrase;
    }

    public static function groupName(int $group): string
    {
        return 'g'.$group;
    }

    /**
     * The searches of the first multi search: the page, then a search per group, then (without options) the lowest and
     * the highest price; with a phrase one search with all of them as aggregations ("prices" for the price range).
     *
     * @return list<array<string, mixed>>
     */
    public function searches(int $from, int $size): array
    {
        $options = self::options($this->criteria);
        $all = $this->productFilters($options, $this->criteria->availableOnly || $this->priced(), $this->priced());
        $page = [
            'sort' => self::sort($this->criteria->sort()),
            'track_total_hits' => true,
            'from' => $from,
            'size' => $size,
        ];

        if ($this->isOneSearch()) {
            $aggregations = [];
            foreach ($this->groups as $index => $group) {
                $aggregations[self::groupName($index)] = ['filter' => self::filterOf($this->groupFilters($group)), ...self::aggs($this->firstAggregations($group))];
            }
            if ($this->priceOfProducts) {
                $aggregations['prices'] = [
                    'filter' => ['term' => ['purchasable' => true]],
                    'aggs' => ['min' => ['min' => ['field' => 'from_gross']], 'max' => ['max' => ['field' => 'max_gross']]],
                ];
            }
            // No "aggs" without any: three axes or more leave no group and no price range to count.
            $search = ['query' => $this->query([]), ...$page, ...self::aggs($aggregations)];
            if ([] !== $all) {
                $search['post_filter'] = ['bool' => ['filter' => $all]];
            }

            return [$search];
        }

        $searches = [['query' => $this->query($all), ...$page]];
        foreach ($this->groups as $group) {
            $searches[] = $this->groupSearch($group, $this->firstAggregations($group));
        }
        if ($this->priceOfProducts) {
            foreach (['from_gross' => 'asc', 'max_gross' => 'desc'] as $field => $order) {
                $searches[] = [
                    'query' => $this->query([['term' => ['purchasable' => true]]]),
                    'size' => 1,
                    '_source' => false,
                    'track_total_hits' => false,
                    'sort' => [[$field => $order]],
                ];
            }
        }

        return $searches;
    }

    /**
     * The second multi search of the groups with more tokens than the size of terms: the ten axes without selection,
     * the categories and the availability by their tokens, and the thirty values of every selected axis with its
     * selected values.
     *
     * @param list<int> $groups the positions in {@see $groups}
     *
     * @return list<array<string, mixed>> a search per group, or one search with a phrase
     */
    public function axesSearches(array $groups): array
    {
        return $this->overflowSearches(array_map(fn (int $index): array => [$index, $this->axesAggregations($this->groups[$index])], $groups));
    }

    /**
     * The third multi search: the thirty values of every axis the second one found.
     *
     * @param array<int, list<string>> $axes the axes of each group, by its position in {@see $groups}
     *
     * @return list<array<string, mixed>> a search per group, or one search with a phrase
     */
    public function valuesSearches(array $axes): array
    {
        $parts = [];
        foreach ($axes as $index => $names) {
            $parts[] = [$index, $this->valuesAggregations($this->groups[$index], $names)];
        }

        return $this->overflowSearches($parts);
    }

    /**
     * @return list<FacetGroup>
     */
    private static function plan(ProductSearchCriteria $criteria): array
    {
        $options = self::options($criteria);
        $count = \count($options);
        $priced = null !== $criteria->priceMin || null !== $criteria->priceMax;
        $buyable = $criteria->availableOnly || $priced;
        /** @var array<string, FacetGroup> $groups */
        $groups = [];

        // A variant has at most two options: with three axes or more nothing matches and nothing is counted.
        if ($count <= 2) {
            $categories = [] !== $criteria->facetCategoryIds;
            // With two axes no other axis can be on the variant.
            $freeAxes = $count <= 1;
            // The availability of a listing without conditions is the token `$`.
            $availability = 0 === $count && !$buyable;
            // The availability needs no other axis, so it comes with the axes without selection.
            if ($categories || $freeAxes) {
                self::need($groups, new FacetGroup(
                    self::kind($options, $priced),
                    $options,
                    $buyable,
                    $priced,
                    categories: $categories,
                    freeAxes: $freeAxes,
                    excluded: $freeAxes ? array_column($options, 0) : [],
                    availability: $availability,
                ));
            }
        }
        if (1 <= $count && $count <= 2) {
            // The price range of buyable variants with the options, and without availability the products with one.
            self::need($groups, new FacetGroup(self::kind($options, false), $options, true, false, count: !$buyable, price: true));
        }
        foreach ($options as $position => $option) {
            $others = $options;
            unset($others[$position]);
            $others = array_values($others);
            // The values of a selected axis without its own condition; on a variant with two other options none.
            if (\count($others) <= 1) {
                self::need($groups, new FacetGroup(self::kind($others, $priced), $others, $buyable, $priced, selected: [$option]));
            }
        }

        return array_values($groups);
    }

    /**
     * Adds what a group counts to the group of the same conditions.
     *
     * @param array<string, FacetGroup> $groups
     */
    private static function need(array &$groups, FacetGroup $group): void
    {
        $sorted = $group->options;
        usort($sorted, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $key = json_encode([array_map(static function (array $option): array {
            $values = $option[1];
            sort($values, \SORT_STRING);

            return [$option[0], $values];
        }, $sorted), $group->buyable, $group->priced], \JSON_THROW_ON_ERROR);

        $groups[$key] = isset($groups[$key]) ? $groups[$key]->with($group) : $group;
    }

    /**
     * @param list<array{string, list<string>}> $options
     *
     * @return FacetGroup::TOKENS|FacetGroup::PAIRS|FacetGroup::NESTED|FacetGroup::PLAIN
     */
    private static function kind(array $options, bool $priced): string
    {
        return match (true) {
            [] === $options && !$priced => FacetGroup::TOKENS,
            1 === \count($options) && 1 === \count($options[0][1]) && !$priced => FacetGroup::PAIRS,
            \count($options) <= 1 => FacetGroup::NESTED,
            default => FacetGroup::PLAIN,
        };
    }

    /**
     * The options of the criteria with the axes as strings (PHP turns a numeric axis name into an int key).
     *
     * @return list<array{string, list<string>}>
     */
    private static function options(ProductSearchCriteria $criteria): array
    {
        $options = [];
        foreach ($criteria->options as $axis => $values) {
            $options[] = [(string) $axis, $values];
        }

        return $options;
    }

    private function priced(): bool
    {
        return null !== $this->criteria->priceMin || null !== $this->criteria->priceMax;
    }

    /**
     * The aggregations of a group in the first multi search. The group without conditions is always the same request
     * (whatever it counts in this listing), so the listings share its entry of the request cache.
     *
     * @return array<string, mixed>
     */
    private function firstAggregations(FacetGroup $group): array
    {
        if (FacetGroup::TOKENS === $group->kind) {
            return ['tokens' => ['terms' => ['field' => self::tokenField($group), 'size' => $this->termsSize, 'order' => self::BY_COUNT]]];
        }

        $aggregations = [];
        $matching = [];
        if (FacetGroup::PAIRS === $group->kind && $group->countsValues()) {
            [$axis, [$value]] = $group->options[0];
            $aggregations['pairs'] = ['terms' => ['field' => self::pairField($group), 'include' => ProductFacetTokens::pairPartnersPattern($axis, $value), 'size' => $this->termsSize, 'order' => self::BY_COUNT]];
        }
        if (FacetGroup::NESTED === $group->kind && $group->countsValues()) {
            if ($group->freeAxes) {
                $matching['axes'] = self::nestedTerms(self::VARIANTS.'.axes', $this->termsSize, exclude: $group->excluded);
            }
            // The values of every axis of the variants when the axes without selection are counted, else only those
            // of the selected axes.
            $matching['options'] = self::nestedTerms(self::VARIANTS.'.options', $this->termsSize, include: $group->freeAxes ? null : self::anyOf(array_map(
                static fn (array $selected): string => ProductFacetTokens::optionsOfAxisPattern($selected[0]),
                $group->selected,
            )));
        }
        if ($group->price) {
            $matching['min'] = ['min' => ['field' => self::VARIANTS.'.gross']];
            $matching['max'] = ['max' => ['field' => self::VARIANTS.'.gross']];
        }
        if ([] !== $matching) {
            $aggregations[self::VARIANTS] = $this->nestedAggregation($group, $matching);
        }
        if ($group->categories) {
            $categories = [];
            foreach ($this->criteria->facetCategoryIds as $categoryId) {
                $categories[$categoryId] = ['term' => ['category_ancestor_ids' => $categoryId]];
            }
            $aggregations['categories'] = ['filters' => ['filters' => $categories]];
        }

        return $aggregations;
    }

    /**
     * @return array<string, mixed>
     */
    private function axesAggregations(FacetGroup $group): array
    {
        $aggregations = [];
        if (FacetGroup::NESTED === $group->kind) {
            $matching = [];
            if ($group->freeAxes) {
                $matching['axes'] = self::nestedTerms(self::VARIANTS.'.axes', self::MAX_AXES, exclude: $group->excluded);
            }
            foreach ($group->selected as $position => [$axis, $values]) {
                $matching['top_'.$position] = self::nestedTerms(self::VARIANTS.'.options', self::MAX_VALUES_PER_AXIS, include: ProductFacetTokens::optionsOfAxisPattern($axis));
                $matching['chosen_'.$position] = self::nestedTerms(self::VARIANTS.'.options', \count($values), include: array_map(
                    static fn (string $value): string => ProductFacetTokens::optionToken($axis, $value),
                    $values,
                ));
            }

            return [self::VARIANTS => $this->nestedAggregation($group, $matching)];
        }

        if (FacetGroup::PAIRS === $group->kind) {
            [$pairAxis, [$pairValue]] = $group->options[0];
            if ($group->freeAxes) {
                $aggregations['axes'] = self::terms(self::pairField($group), self::MAX_AXES, ProductFacetTokens::pairAxesPattern($pairAxis, $pairValue));
            }
            foreach ($group->selected as $position => [$axis, $values]) {
                $aggregations['top_'.$position] = self::terms(self::pairField($group), self::MAX_VALUES_PER_AXIS, ProductFacetTokens::pairOptionsOfAxisPattern($pairAxis, $pairValue, $axis));
                $aggregations['chosen_'.$position] = self::terms(self::pairField($group), \count($values), array_map(
                    static fn (string $value): string => ProductFacetTokens::pairToken(ProductFacetTokens::optionToken($pairAxis, $pairValue), ProductFacetTokens::optionToken($axis, $value)),
                    $values,
                ));
            }

            return $aggregations;
        }

        $field = self::tokenField($group);
        if ($group->freeAxes) {
            $aggregations['axes'] = self::terms($field, self::MAX_AXES, ProductFacetTokens::axesPattern());
        }
        if ($group->categories) {
            $aggregations['categories'] = self::terms($field, \count($this->criteria->facetCategoryIds), array_map(ProductFacetTokens::categoryToken(...), $this->criteria->facetCategoryIds));
        }
        if ($group->availability) {
            $aggregations['available'] = self::terms($field, 1, [ProductFacetTokens::PURCHASABLE]);
        }
        foreach ($group->selected as $position => [$axis, $values]) {
            $aggregations['top_'.$position] = self::terms($field, self::MAX_VALUES_PER_AXIS, ProductFacetTokens::optionsOfAxisPattern($axis));
            $aggregations['chosen_'.$position] = self::terms($field, \count($values), array_map(
                static fn (string $value): string => ProductFacetTokens::optionToken($axis, $value),
                $values,
            ));
        }

        return $aggregations;
    }

    /**
     * @param list<string> $axes
     *
     * @return array<string, mixed>
     */
    private function valuesAggregations(FacetGroup $group, array $axes): array
    {
        $aggregations = [];
        foreach ($axes as $position => $axis) {
            $aggregations['values_'.$position] = match ($group->kind) {
                FacetGroup::NESTED => self::nestedTerms(self::VARIANTS.'.options', self::MAX_VALUES_PER_AXIS, include: ProductFacetTokens::optionsOfAxisPattern($axis)),
                FacetGroup::PAIRS => self::terms(self::pairField($group), self::MAX_VALUES_PER_AXIS, ProductFacetTokens::pairOptionsOfAxisPattern($group->options[0][0], $group->options[0][1][0], $axis)),
                default => self::terms(self::tokenField($group), self::MAX_VALUES_PER_AXIS, ProductFacetTokens::optionsOfAxisPattern($axis)),
            };
        }

        return FacetGroup::NESTED === $group->kind ? [self::VARIANTS => $this->nestedAggregation($group, $aggregations)] : $aggregations;
    }

    /**
     * @param list<array{int, array<string, mixed>}> $parts the position of a group and its aggregations
     *
     * @return list<array<string, mixed>>
     */
    private function overflowSearches(array $parts): array
    {
        if ($this->isOneSearch()) {
            $aggregations = [];
            foreach ($parts as [$index, $groupAggregations]) {
                $aggregations[self::groupName($index)] = ['filter' => self::filterOf($this->groupFilters($this->groups[$index])), 'aggs' => $groupAggregations];
            }

            return [['query' => $this->query([]), 'size' => 0, 'track_total_hits' => false, 'aggs' => $aggregations]];
        }

        return array_map(fn (array $part): array => $this->groupSearch($this->groups[$part[0]], $part[1], count: false), $parts);
    }

    /**
     * @param array<string, mixed> $aggregations
     *
     * @return array<string, mixed>
     */
    private function groupSearch(FacetGroup $group, array $aggregations, ?bool $count = null): array
    {
        return [
            'query' => $this->query($this->groupFilters($group)),
            'size' => 0,
            'track_total_hits' => $count ?? $group->count,
            ...self::aggs($aggregations),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupFilters(FacetGroup $group): array
    {
        return $this->productFilters($group->options, $group->buyable, $group->priced);
    }

    /**
     * "A variant with these options (any value of an axis, every axis), buyable, with a price in the range" as queries
     * on the fields of the product (spec search, "Zapytanie"): one axis is a token of `facets` (`buy_facets`), two axes a
     * pair of `pair_any` (`pair_buy`), three axes nothing; the price range is a buyable price of `buy_prices`, and
     * together with an option a nested query on the variants (the price and the option on one variant).
     *
     * @param list<array{string, list<string>}> $options
     *
     * @return list<array<string, mixed>>
     */
    private function productFilters(array $options, bool $buyable, bool $priced): array
    {
        $filters = match (\count($options)) {
            0 => $buyable && !$priced ? [['term' => ['purchasable' => true]]] : [],
            1 => [['terms' => [$buyable ? 'buy_facets' : 'facets' => array_map(
                static fn (string $value): string => ProductFacetTokens::optionToken($options[0][0], $value),
                $options[0][1],
            )]]],
            2 => [['terms' => [$buyable ? 'pair_buy' : 'pair_any' => self::pairs($options[0], $options[1])]]],
            default => [['match_none' => (object) []]],
        };
        if ($priced) {
            $filters[] = ['range' => ['buy_prices' => $this->range()]];
            if (1 <= \count($options) && \count($options) <= 2) {
                $filters[] = ['nested' => ['path' => self::VARIANTS, 'query' => ['bool' => ['filter' => $this->variantFilters($options, true, true)]]]];
            }
        }

        return $filters;
    }

    /**
     * The conditions on one nested variant.
     *
     * @param list<array{string, list<string>}> $options
     *
     * @return list<array<string, mixed>>
     */
    private function variantFilters(array $options, bool $buyable, bool $priced): array
    {
        $filters = [];
        foreach ($options as [$axis, $values]) {
            $filters[] = ['terms' => [self::VARIANTS.'.options' => array_map(static fn (string $value): string => ProductFacetTokens::optionToken($axis, $value), $values)]];
        }
        if ($priced) {
            $filters[] = ['range' => [self::VARIANTS.'.gross' => $this->range()]];
        }
        if ($buyable) {
            $filters[] = ['term' => [self::VARIANTS.'.purchasable' => true]];
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $matching the aggregations of the variants that meet the conditions of the group
     *
     * @return array<string, mixed>
     */
    private function nestedAggregation(FacetGroup $group, array $matching): array
    {
        return [
            'nested' => ['path' => self::VARIANTS],
            'aggs' => ['matching' => [
                'filter' => self::filterOf($this->variantFilters($group->options, $group->buyable, $group->priced)),
                'aggs' => $matching,
            ]],
        ];
    }

    /**
     * Every pair of the selected values of two axes, once.
     *
     * @param array{string, list<string>} $first
     * @param array{string, list<string>} $second
     *
     * @return list<string>
     */
    private static function pairs(array $first, array $second): array
    {
        $pairs = [];
        foreach ($first[1] as $x) {
            foreach ($second[1] as $y) {
                $pairs[] = ProductFacetTokens::pairToken(ProductFacetTokens::optionToken($first[0], $x), ProductFacetTokens::optionToken($second[0], $y));
            }
        }

        return $pairs;
    }

    /**
     * @return array{gte?: int, lte?: int}
     */
    private function range(): array
    {
        $range = [];
        if (null !== $this->criteria->priceMin) {
            $range['gte'] = $this->criteria->priceMin;
        }
        if (null !== $this->criteria->priceMax) {
            $range['lte'] = $this->criteria->priceMax;
        }

        return $range;
    }

    /**
     * The status, the category and the phrase, with more filters.
     *
     * @param list<array<string, mixed>> $filters
     *
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        $bool = ['filter' => [['term' => ['status' => ProductStatus::Published->value]]]];
        if (null !== $this->criteria->categoryId) {
            $bool['filter'][] = ['term' => ['category_ancestor_ids' => $this->criteria->categoryId]];
        }
        array_push($bool['filter'], ...$filters);
        if (null !== $this->criteria->phrase) {
            $bool['must'] = [self::phrase($this->criteria->phrase)];
        }

        return ['bool' => $bool];
    }

    /**
     * Every word must match, in the exact branch also across fields; the typo branch is weaker and needs the words
     * in one field (cross_fields takes no fuzziness).
     *
     * @return array<string, mixed>
     */
    private static function phrase(string $phrase): array
    {
        return ['bool' => ['minimum_should_match' => 1, 'should' => [
            ['multi_match' => [
                'query' => $phrase,
                'type' => 'cross_fields',
                'operator' => 'and',
                'fields' => ['name^3', 'name.folded^3', 'category_names^2', 'category_names.folded^2', 'short_description', 'short_description.folded'],
            ]],
            ['multi_match' => [
                'query' => $phrase,
                'type' => 'most_fields',
                'operator' => 'and',
                'fuzziness' => 'AUTO',
                'prefix_length' => 1,
                'max_expansions' => 20,
                'boost' => 0.3,
                'fields' => ['name.folded^3', 'category_names.folded^2', 'short_description.folded'],
            ]],
        ]]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sort(ProductSort $sort): array
    {
        return match ($sort) {
            ProductSort::Relevance => [['_score' => 'desc'], ['name.sort' => 'asc'], ['id' => 'asc']],
            ProductSort::Name => [['name.sort' => 'asc'], ['id' => 'asc']],
            ProductSort::PriceAsc => [['from_gross' => ['order' => 'asc', 'missing' => '_last']], ['name.sort' => 'asc'], ['id' => 'asc']],
            ProductSort::PriceDesc => [['from_gross' => ['order' => 'desc', 'missing' => '_last']], ['name.sort' => 'asc'], ['id' => 'asc']],
        };
    }

    private static function tokenField(FacetGroup $group): string
    {
        return $group->buyable ? 'buy_facets' : 'facets';
    }

    private static function pairField(FacetGroup $group): string
    {
        return $group->buyable ? 'pair_buy' : 'pair_any';
    }

    /**
     * A terms aggregation over tokens, most products first, then by token.
     *
     * @param string|list<string> $include a regular expression or the exact tokens
     *
     * @return array<string, mixed>
     */
    private static function terms(string $field, int $size, string|array $include): array
    {
        return ['terms' => ['field' => $field, 'include' => $include, 'size' => $size, 'order' => self::BY_COUNT]];
    }

    /**
     * A terms aggregation over the nested variants, counted and ordered by products.
     *
     * @param string|list<string>|null $include a regular expression or the exact terms
     * @param list<string>             $exclude
     *
     * @return array<string, mixed>
     */
    private static function nestedTerms(string $field, int $size, string|array|null $include = null, array $exclude = []): array
    {
        $terms = ['field' => $field, 'size' => $size, 'order' => self::BY_PRODUCTS];
        if (null !== $include) {
            $terms['include'] = $include;
        }
        if ([] !== $exclude) {
            $terms['exclude'] = $exclude;
        }

        return ['terms' => $terms, 'aggs' => ['product' => ['reverse_nested' => (object) []]]];
    }

    /**
     * @param list<string> $patterns regular expressions
     */
    private static function anyOf(array $patterns): string
    {
        return 1 === \count($patterns) ? $patterns[0] : '('.implode(')|(', $patterns).')';
    }

    /**
     * @param list<array<string, mixed>> $filters
     *
     * @return array<string, mixed>
     */
    private static function filterOf(array $filters): array
    {
        return [] === $filters ? ['match_all' => (object) []] : ['bool' => ['filter' => $filters]];
    }

    /**
     * @param array<string, mixed> $aggregations
     *
     * @return array{aggs?: array<string, mixed>}
     */
    private static function aggs(array $aggregations): array
    {
        return [] === $aggregations ? [] : ['aggs' => $aggregations];
    }
}
