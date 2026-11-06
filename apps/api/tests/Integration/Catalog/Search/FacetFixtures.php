<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;

/**
 * Deterministic product sets for the facet tests and a brute-force model of the facets in the spec, computed in
 * PHP from the documents (no Elasticsearch): the expected answer the real aggregations are compared with.
 */
final class FacetFixtures
{
    public const int MAX_AXES = 10;
    public const int MAX_VALUES = 30;

    /**
     * @param array<string, string> $options axis => value
     */
    public static function variant(array $options, ?int $gross, bool $purchasable): VariantDocument
    {
        $axes = array_map(strval(...), array_keys($options));

        return new VariantDocument(
            $gross,
            $purchasable,
            array_map(static fn (string $axis, string $value): string => $axis.VariantDocument::OPTION_SEPARATOR.$value, $axes, $options),
        );
    }

    /**
     * @param list<VariantDocument> $variants
     */
    public static function product(string $id, array $variants): ProductDocument
    {
        $buyable = array_filter(array_map(static fn (VariantDocument $v): ?int => $v->purchasable ? $v->gross : null, $variants), static fn (?int $gross): bool => null !== $gross);

        return new ProductDocument(
            $id,
            1,
            'Produkt '.$id,
            'produkt-'.$id,
            'Opis',
            'c',
            ['c'],
            ['c'],
            null,
            [],
            $variants,
            [] === $buyable ? null : min($buyable),
        );
    }

    /**
     * 80 products with 13 variants each: axes B01..B12 with 40 values (every pair on two products, 480 pairs) and the
     * axis "Alfa" (on all products, 80 values with one product each). Alfa is the first of the ten axes by name
     * among equals, and its values are rarer than every value of the B axes.
     *
     * @return list<ProductDocument>
     */
    public static function manyAxes(): array
    {
        $products = [];
        for ($p = 0; $p < 80; ++$p) {
            $variants = [];
            for ($axis = 1; $axis <= 12; ++$axis) {
                $variants[] = self::variant([\sprintf('B%02d', $axis) => \sprintf('v%02d', $p % 40 + 1)], 1000, true);
            }
            $variants[] = self::variant(['Alfa' => \sprintf('t%02d', $p + 1)], 1000, true);
            $products[] = self::product(\sprintf('m%03d', $p), $variants);
        }

        return $products;
    }

    /**
     * A skewed random set: 10 axes with 45 values each (common values first), products with 3-5 axes and 1-6 variants
     * of 2-4 axis values, a price on 90% of the variants, 60% of the priced ones buyable.
     *
     * @return list<ProductDocument>
     */
    public static function random(int $count = 400, int $seed = 20_261_106): array
    {
        mt_srand($seed);
        $axes = ['Kolor', 'Rozmiar', 'Materiał', 'Długość', 'Waga', 'Pojemność', 'Wzór', 'Sezon', 'Płeć', 'Marka'];
        $value = static fn (string $axis): string => \sprintf('%s %02d', $axis, (int) floor(45 * (mt_rand() / mt_getrandmax()) ** 2.5) + 1);

        $products = [];
        for ($p = 0; $p < $count; ++$p) {
            $own = $axes;
            shuffle($own);
            $own = \array_slice($own, 0, mt_rand(3, 5));
            $variants = [];
            for ($v = mt_rand(1, 6); $v > 0; --$v) {
                $chosen = $own;
                shuffle($chosen);
                $options = [];
                foreach (\array_slice($chosen, 0, mt_rand(2, min(4, \count($own)))) as $axis) {
                    $options[$axis] = $value($axis);
                }
                $priced = mt_rand(1, 10) > 1;
                $variants[] = self::variant($options, $priced ? mt_rand(50, 300) * 100 : null, $priced && mt_rand(1, 10) <= 6);
            }
            $products[] = self::product(\sprintf('r%04d', $p), $variants);
        }

        return $products;
    }

    /**
     * The index bulk body of the documents.
     *
     * @param list<ProductDocument> $documents
     *
     * @return list<array<string, mixed>>
     */
    public static function bulk(array $documents): array
    {
        $body = [];
        foreach ($documents as $document) {
            $body[] = ['index' => ['_id' => $document->id]];
            $body[] = ProductIndexDefinition::source($document);
        }

        return $body;
    }

    /**
     * The facets of the spec, by brute force.
     *
     * @param list<ProductDocument> $documents
     *
     * @return array{total: int, available: int, price: array{int, int}|null, options: list<array{string, list<array{string, int}>}>}
     */
    public static function expected(array $documents, ProductSearchCriteria $criteria): array
    {
        $selected = array_map(strval(...), array_keys($criteria->options));
        $total = 0;
        $available = 0;
        $price = null;
        /** @var array<string, array<string, int>> $free axis => value => products */
        $free = [];
        /** @var array<string, array<string, int>> $own axis => value => products, without the condition on the axis */
        $own = [];
        /** @var array<string, int> $axisProducts */
        $axisProducts = [];

        foreach ($documents as $document) {
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
            $total += $matchesAll ? 1 : 0;
            $available += $availableMatch ? 1 : 0;
            foreach ($freeValues as $axis => $values) {
                $axis = (string) $axis;
                if (\in_array($axis, $selected, true)) {
                    continue;
                }
                $axisProducts[$axis] = ($axisProducts[$axis] ?? 0) + 1;
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

        // The ten unselected axes with most products, then every selected axis (all of them have `total` products).
        $ranked = array_keys($axisProducts);
        usort($ranked, static fn (string $a, string $b): int => $axisProducts[$b] <=> $axisProducts[$a] ?: strcmp($a, $b));
        $axes = \array_slice($ranked, 0, self::MAX_AXES);
        foreach ($selected as $axis) {
            $axes[] = $axis;
            $axisProducts[$axis] = $total;
        }
        usort($axes, static fn (string $a, string $b): int => $axisProducts[$b] <=> $axisProducts[$a] ?: strcmp($a, $b));

        $options = [];
        foreach ($axes as $axis) {
            $counts = \in_array($axis, $selected, true) ? ($own[$axis] ?? []) : $free[$axis];
            $chosen = array_map(strval(...), $criteria->options[$axis] ?? []);
            foreach ($chosen as $value) {
                $counts[$value] ??= 0;
            }
            $list = [];
            foreach ($counts as $value => $count) {
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

        return ['total' => $total, 'available' => $available, 'price' => $price, 'options' => $options];
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
