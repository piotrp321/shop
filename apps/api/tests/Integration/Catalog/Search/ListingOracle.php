<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\OptionFacet;
use App\Catalog\Application\Search\OptionValue;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\ProductSort;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Product\ProductStatus;

/**
 * The listing of the spec (search, S2, S3 and "Znaczenie liczb") computed in PHP straight from the documents, without
 * Elasticsearch: the total, the first page, the category, option, price and availability facets. The expected answer
 * of every model of the index (spec "Testy", "Zgodność modelu R").
 *
 * Every condition on the variants holds for one and the same variant: the values of the selected axes (any value of
 * an axis, every axis), the price range on a variant that can be bought, and a variant that can be bought when only
 * available products are wanted. A facet is counted with every condition except its own.
 *
 * Two parts of the listing are Elasticsearch's own and are given to the oracle: whether a product matches the phrase,
 * and the order of the relevance sort, which the catalog of the test keeps as a tie of every matching product (then by
 * name and id, as the sort of the index does).
 */
final readonly class ListingOracle
{
    private const int MAX_AXES = 10;
    private const int MAX_VALUES = 30;

    /**
     * @param list<ProductDocument>          $documents     every document of the index, drafts too
     * @param \Closure(ProductDocument):bool $matchesPhrase whether the document matches the phrase of the criteria
     */
    public function __construct(private array $documents, private \Closure $matchesPhrase)
    {
    }

    /**
     * The listing in the form of {@see shape()}.
     *
     * @return array{total: int, reachable: int, page: list<string>, categories: array<string, int>, options: list<array{string, list<array{string, int}>}>, price: array{int, int}|null, available: int}
     */
    public function listing(ProductSearchCriteria $criteria): array
    {
        $selected = array_map(strval(...), array_keys($criteria->options));
        $matches = [];
        $available = 0;
        $price = null;
        /** @var array<string, int> $categories */
        $categories = [];
        /** @var array<string, array<string, int>> $free axis => value => products */
        $free = [];
        /** @var array<string, int> $freeAxes axis => products */
        $freeAxes = [];
        /** @var array<string, array<string, int>> $own axis => value => products, without the condition of the axis */
        $own = [];

        foreach ($this->documents as $document) {
            if (!$this->inListing($document, $criteria)) {
                continue;
            }
            $matchesAll = false;
            $availableMatch = false;
            $freeValues = [];
            $ownValues = [];
            foreach ($document->variants as $variant) {
                if (self::matches($variant, $criteria)) {
                    $matchesAll = true;
                    foreach (self::pairs($variant) as [$axis, $value]) {
                        $freeValues[$axis][$value] = true;
                    }
                }
                if (self::matches($variant, $criteria, forceAvailable: true)) {
                    $availableMatch = true;
                }
                if (null !== $variant->gross && self::matches($variant, $criteria, withPrice: false, forceAvailable: true)) {
                    $price = null === $price ? [$variant->gross, $variant->gross] : [min($price[0], $variant->gross), max($price[1], $variant->gross)];
                }
                foreach ($selected as $axis) {
                    if (self::matches($variant, $criteria, exceptAxis: $axis)) {
                        foreach (self::pairs($variant) as [$pairAxis, $value]) {
                            if ($pairAxis === $axis) {
                                $ownValues[$axis][$value] = true;
                            }
                        }
                    }
                }
            }

            if ($matchesAll) {
                $matches[] = $document;
                foreach ($criteria->facetCategoryIds as $categoryId) {
                    if (\in_array($categoryId, $document->categoryAncestorIds, true)) {
                        $categories[$categoryId] = ($categories[$categoryId] ?? 0) + 1;
                    }
                }
            }
            $available += $availableMatch ? 1 : 0;
            foreach ($freeValues as $axis => $values) {
                $axis = (string) $axis;
                if (\in_array($axis, $selected, true)) {
                    continue;
                }
                $freeAxes[$axis] = ($freeAxes[$axis] ?? 0) + 1;
                foreach (array_keys($values) as $value) {
                    $free[$axis][(string) $value] = ($free[$axis][(string) $value] ?? 0) + 1;
                }
            }
            foreach ($ownValues as $axis => $values) {
                foreach (array_keys($values) as $value) {
                    $own[(string) $axis][(string) $value] = ($own[(string) $axis][(string) $value] ?? 0) + 1;
                }
            }
        }

        $total = \count($matches);
        // The order of the buckets of a terms aggregation: most products first, then by key.
        uksort($categories, static fn (string $a, string $b): int => $categories[$b] <=> $categories[$a] ?: strcmp($a, $b));

        return [
            'total' => $total,
            'reachable' => $total,
            'page' => $this->page($matches, $criteria),
            'categories' => $categories,
            'options' => self::options($criteria, $selected, $total, $freeAxes, $free, $own),
            'price' => $price,
            'available' => $available,
        ];
    }

    /**
     * A search result in the form of {@see listing()}: the ids of the page in their order instead of whole documents.
     *
     * @return array{total: int, reachable: int, page: list<string>, categories: array<string, int>, options: list<array{string, list<array{string, int}>}>, price: array{int, int}|null, available: int}
     */
    public static function shape(SearchResult $result): array
    {
        return [
            'total' => $result->total,
            'reachable' => $result->reachable,
            'page' => array_map(static fn (ProductDocument $document): string => $document->id, $result->items),
            'categories' => array_map(intval(...), $result->facets->categoryCounts),
            'options' => array_map(static fn (OptionFacet $facet): array => [
                $facet->axis,
                array_map(static fn (OptionValue $value): array => [$value->value, $value->count], $facet->values),
            ], $result->facets->options),
            'price' => null === $result->facets->price ? null : [$result->facets->price->min, $result->facets->price->max],
            'available' => $result->facets->availableCount,
        ];
    }

    private function inListing(ProductDocument $document, ProductSearchCriteria $criteria): bool
    {
        return ProductStatus::Published === $document->status
            && (null === $criteria->categoryId || \in_array($criteria->categoryId, $document->categoryAncestorIds, true))
            && (null === $criteria->phrase || ($this->matchesPhrase)($document));
    }

    /**
     * The ids of the first page: relevance ties (the catalog of the test), so by name (lower case: the names are ASCII)
     * and id; the price sorts by the "from" price with the products without one last, then by name and id.
     *
     * @param list<ProductDocument> $matches
     *
     * @return list<string>
     */
    private function page(array $matches, ProductSearchCriteria $criteria): array
    {
        $byName = static fn (ProductDocument $a, ProductDocument $b): int => strcmp(strtolower($a->name), strtolower($b->name)) ?: strcmp($a->id, $b->id);
        $sort = $criteria->sort();
        usort($matches, match ($sort) {
            ProductSort::Relevance, ProductSort::Name => $byName,
            ProductSort::PriceAsc, ProductSort::PriceDesc => static function (ProductDocument $a, ProductDocument $b) use ($byName, $sort): int {
                if (null === $a->fromGross || null === $b->fromGross) {
                    return (null === $a->fromGross) <=> (null === $b->fromGross) ?: $byName($a, $b);
                }

                return (ProductSort::PriceAsc === $sort ? $a->fromGross <=> $b->fromGross : $b->fromGross <=> $a->fromGross) ?: $byName($a, $b);
            },
        });
        $from = ($criteria->page - 1) * $criteria->perPage;

        return array_map(static fn (ProductDocument $document): string => $document->id, \array_slice($matches, $from, $criteria->perPage));
    }

    /**
     * The ten unselected axes with most products, each with its thirty values with most products, and every selected
     * axis (with `total` products) with its values counted without its own condition, the selected ones always listed,
     * also with 0 and beyond the thirtieth. Axes by products, then by name; values by products, then by value.
     *
     * @param list<string>                      $selected
     * @param array<string, int>                $freeAxes
     * @param array<string, array<string, int>> $free
     * @param array<string, array<string, int>> $own
     *
     * @return list<array{string, list<array{string, int}>}>
     */
    private static function options(ProductSearchCriteria $criteria, array $selected, int $total, array $freeAxes, array $free, array $own): array
    {
        $ranked = array_map(strval(...), array_keys($freeAxes));
        usort($ranked, static fn (string $a, string $b): int => $freeAxes[$b] <=> $freeAxes[$a] ?: strcmp($a, $b));
        $axes = \array_slice($ranked, 0, self::MAX_AXES);
        $counts = $freeAxes;
        foreach ($selected as $axis) {
            $axes[] = $axis;
            $counts[$axis] = $total;
        }
        usort($axes, static fn (string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));

        $options = [];
        foreach ($axes as $axis) {
            $isSelected = \in_array($axis, $selected, true);
            $values = $isSelected ? ($own[$axis] ?? []) : $free[$axis];
            $chosen = $isSelected ? array_map(strval(...), $criteria->options[$axis]) : [];
            foreach ($chosen as $value) {
                $values[$value] ??= 0;
            }
            $list = [];
            foreach ($values as $value => $count) {
                $list[] = [(string) $value, $count];
            }
            usort($list, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));
            $kept = \array_slice($list, 0, self::MAX_VALUES);
            foreach (\array_slice($list, self::MAX_VALUES) as $entry) {
                if (\in_array($entry[0], $chosen, true)) {
                    $kept[] = $entry;
                }
            }
            $options[] = [$axis, $kept];
        }

        return $options;
    }

    /**
     * @return list<array{string, string}> axis, value
     */
    private static function pairs(VariantDocument $variant): array
    {
        return array_map(static function (string $option): array {
            [$axis, $value] = explode(VariantDocument::OPTION_SEPARATOR, $option, 2);

            return [$axis, $value];
        }, $variant->options);
    }

    private static function matches(VariantDocument $variant, ProductSearchCriteria $criteria, ?string $exceptAxis = null, bool $withPrice = true, bool $forceAvailable = false): bool
    {
        foreach ($criteria->options as $axis => $values) {
            $axis = (string) $axis;
            if ($axis === $exceptAxis) {
                continue;
            }
            $has = false;
            foreach ($values as $value) {
                $has = $has || \in_array($axis.VariantDocument::OPTION_SEPARATOR.$value, $variant->options, true);
            }
            if (!$has) {
                return false;
            }
        }
        $priced = $withPrice && (null !== $criteria->priceMin || null !== $criteria->priceMax);
        if ($priced && (null === $variant->gross || $variant->gross < ($criteria->priceMin ?? \PHP_INT_MIN) || $variant->gross > ($criteria->priceMax ?? \PHP_INT_MAX))) {
            return false;
        }

        return !($forceAvailable || $criteria->availableOnly || $priced) || $variant->purchasable;
    }
}
