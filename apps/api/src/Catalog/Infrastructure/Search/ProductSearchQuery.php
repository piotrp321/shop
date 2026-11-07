<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\ProductSort;
use App\Catalog\Application\Search\VariantDocument;

/**
 * The Elasticsearch request body of a product search: the phrase and the category in `query`, the conditions on one
 * variant in `post_filter`, and the facet aggregations, each of which adds the variant conditions without its own.
 */
final readonly class ProductSearchQuery
{
    public const int MAX_AXES = 10;
    public const int MAX_VALUES_PER_AXIS = 30;

    private const string VARIANTS = 'variants';
    private const string PAIRS = 'variants.pairs';
    /** The buckets of a facet: most products first (the reverse_nested count, not the nested documents), then by name. */
    private const array BY_PRODUCTS = [['product' => 'desc'], ['_key' => 'asc']];

    /**
     * @return array<string, mixed>
     */
    public static function body(ProductSearchCriteria $criteria, int $from, int $size): array
    {
        $body = [
            'query' => self::query($criteria),
            'sort' => self::sort($criteria->sort()),
            'track_total_hits' => true,
            'from' => $from,
            'size' => $size,
            'aggs' => self::aggregations($criteria),
        ];
        $conditions = self::conditions($criteria);
        if ([] !== $conditions) {
            $body['post_filter'] = self::nested($conditions);
        }

        return $body;
    }

    /**
     * The name of the aggregation of the selected axis at the position.
     */
    public static function selectedAxisAggregation(int $position): string
    {
        return 'selected_'.$position;
    }

    /**
     * @return array<string, mixed>
     */
    private static function query(ProductSearchCriteria $criteria): array
    {
        $bool = ['filter' => null === $criteria->categoryId ? [] : [['term' => ['category_ancestor_ids' => $criteria->categoryId]]]];
        if (null !== $criteria->phrase) {
            $bool['must'] = [self::phrase($criteria->phrase)];
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function aggregations(ProductSearchCriteria $criteria): array
    {
        $aggregations = [];
        if ([] !== $criteria->facetCategoryIds) {
            $aggregations['categories'] = [
                'filter' => [] === self::conditions($criteria) ? ['match_all' => (object) []] : self::nested(self::conditions($criteria)),
                'aggs' => ['ids' => ['terms' => ['field' => 'category_ancestor_ids', 'include' => $criteria->facetCategoryIds, 'size' => \count($criteria->facetCategoryIds)]]],
            ];
        }

        // The total after adding "only available": a buyable variant with the options and the price range.
        $aggregations['available'] = self::nestedAggregation(
            self::conditions($criteria, forceAvailable: true),
            ['product' => ['reverse_nested' => (object) []]],
        );
        $aggregations['price'] = self::nestedAggregation(
            self::conditions($criteria, withPrice: false, forceAvailable: true),
            ['min' => ['min' => ['field' => 'variants.gross']], 'max' => ['max' => ['field' => 'variants.gross']]],
        );
        // PHP turns a numeric axis name into an int key.
        $selected = array_map(strval(...), array_keys($criteria->options));
        // The unselected axes of the variants that meet every condition, the ten with most products, each with its
        // thirty values with most products. The selected axes are left out: all of them have `total` products and
        // their values come from the aggregation of the axis below. The index has one shard, so the sorted terms are
        // exact; with more shards they would need a shard_size of at least the number of distinct buckets.
        $axes = [
            'terms' => ['field' => self::PAIRS.'.axis', 'size' => self::MAX_AXES, 'order' => self::BY_PRODUCTS],
            'aggs' => [
                'product' => ['reverse_nested' => (object) []],
                'values' => [
                    'terms' => ['field' => self::PAIRS.'.value', 'size' => self::MAX_VALUES_PER_AXIS, 'order' => self::BY_PRODUCTS],
                    'aggs' => ['product' => ['reverse_nested' => (object) []]],
                ],
            ],
        ];
        if ([] !== $selected) {
            $axes['terms']['exclude'] = $selected;
        }
        $aggregations['free'] = self::nestedAggregation(self::conditions($criteria), [
            'pairs' => ['nested' => ['path' => self::PAIRS], 'aggs' => ['axes' => $axes]],
        ]);

        foreach ($selected as $position => $axis) {
            // The values of a selected axis are counted without its own condition: the other values stay on offer.
            $aggregations[self::selectedAxisAggregation($position)] = self::nestedAggregation(
                self::conditions($criteria, exceptAxis: $axis),
                ['pairs' => [
                    'nested' => ['path' => self::PAIRS],
                    'aggs' => ['axis' => [
                        'filter' => ['term' => [self::PAIRS.'.axis' => $axis]],
                        'aggs' => [
                            'top' => [
                                'terms' => ['field' => self::PAIRS.'.value', 'size' => self::MAX_VALUES_PER_AXIS, 'order' => self::BY_PRODUCTS],
                                'aggs' => ['product' => ['reverse_nested' => (object) []]],
                            ],
                            // The selected values, also those cut off by the limit of `top`.
                            'chosen' => [
                                'terms' => ['field' => self::PAIRS.'.value', 'include' => $criteria->options[$axis], 'size' => \count($criteria->options[$axis])],
                                'aggs' => ['product' => ['reverse_nested' => (object) []]],
                            ],
                        ],
                    ]],
                ]],
            );
        }

        return $aggregations;
    }

    /**
     * The conditions on one variant: for every axis one of its values, the price range on a buyable variant, and a
     * buyable variant when only available products are wanted.
     *
     * @param string|null $exceptAxis     an axis whose condition is left out
     * @param bool        $withPrice      whether the price range is a condition
     * @param bool        $forceAvailable whether a buyable variant is a condition even without availableOnly
     *
     * @return list<array<string, mixed>>
     */
    private static function conditions(ProductSearchCriteria $criteria, ?string $exceptAxis = null, bool $withPrice = true, bool $forceAvailable = false): array
    {
        $conditions = [];
        foreach ($criteria->options as $axis => $values) {
            // PHP turns a numeric axis name into an int key.
            $axis = (string) $axis;
            if ($axis !== $exceptAxis) {
                $conditions[] = ['terms' => ['variants.options' => array_map(static fn (string $value): string => self::option($axis, $value), $values)]];
            }
        }
        $buyable = $forceAvailable || $criteria->availableOnly;
        $priced = $withPrice && (null !== $criteria->priceMin || null !== $criteria->priceMax);
        if ($priced) {
            $range = [];
            if (null !== $criteria->priceMin) {
                $range['gte'] = $criteria->priceMin;
            }
            if (null !== $criteria->priceMax) {
                $range['lte'] = $criteria->priceMax;
            }
            $conditions[] = ['range' => ['variants.gross' => $range]];
        }
        if ($buyable || $priced) {
            $conditions[] = ['term' => ['variants.purchasable' => true]];
        }

        return $conditions;
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed>       $aggregations
     *
     * @return array<string, mixed>
     */
    private static function nestedAggregation(array $conditions, array $aggregations): array
    {
        return [
            'nested' => ['path' => self::VARIANTS],
            'aggs' => ['matching' => ['filter' => self::filterOf($conditions), 'aggs' => $aggregations]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $conditions
     *
     * @return array<string, mixed>
     */
    private static function filterOf(array $conditions): array
    {
        return [] === $conditions ? ['match_all' => (object) []] : ['bool' => ['filter' => $conditions]];
    }

    /**
     * @param list<array<string, mixed>> $conditions at least one
     *
     * @return array<string, mixed>
     */
    private static function nested(array $conditions): array
    {
        return ['nested' => ['path' => self::VARIANTS, 'query' => ['bool' => ['filter' => $conditions]]]];
    }

    private static function option(string $axis, string $value): string
    {
        return $axis.VariantDocument::OPTION_SEPARATOR.$value;
    }
}
