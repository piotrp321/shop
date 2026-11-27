<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\ProductSort;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;

/**
 * A catalog built by hand with every edge case of the model of variants (spec search, "Testy", "Zgodność modelu R")
 * and the criteria the listing is compared on. Every product keeps the rules of the domain the index stands on: at
 * most two axes, one value of every axis on each variant, no two variants of a product with the same values (without
 * regard to case), a product without axes has one variant without values; a variant that can be bought has a price.
 * The catalog is checked against the domain classes when it is built.
 *
 * The tree: Sport > Turystyka > Plecaki, Sport > Rower, Dom > Kuchnia, and Duże > Węzeł, Duże > Inne with the
 * products of the overflow of the facets (more than 10 axes and 30 values of an axis, ties at both limits).
 *
 * The phrase matches the products described as "Zestaw testowy" and only them, in one field and with the same text,
 * so the relevance of every match is the same and the order is by name and id.
 */
final class EdgeCaseCatalog
{
    public const string PHRASE = 'zestaw';

    public const string SPORT = '00000000-0000-4000-8000-00000000c001';
    public const string TURYSTYKA = '00000000-0000-4000-8000-00000000c002';
    public const string PLECAKI = '00000000-0000-4000-8000-00000000c003';
    public const string ROWER = '00000000-0000-4000-8000-00000000c004';
    public const string DOM = '00000000-0000-4000-8000-00000000c005';
    public const string KUCHNIA = '00000000-0000-4000-8000-00000000c006';
    public const string DUZE = '00000000-0000-4000-8000-00000000c007';
    public const string WEZEL = '00000000-0000-4000-8000-00000000c008';
    public const string INNE = '00000000-0000-4000-8000-00000000c009';

    // Axes of the edge cases: one is the prefix of another, one has the characters of regular expressions, one the
    // characters of the tokens and a symbol like a separator, one is a number.
    public const string KOLOR = 'Kolor';
    public const string ROZMIAR = 'Rozmiar';
    public const string KOLOR_RAMY = 'Kolor ramy';
    public const string WYMIAR = 'Wym.*(cm)\\';
    public const string ZNAK = "#@\$ \u{241F}";
    public const string ROK = '2024';
    public const string MATERIAL = 'Materiał';
    // An axis named like an axis token: its options must never be read as axes.
    public const string SKALA = '@Skala';
    // Axes of the overflow: the hub of every other axis, the axis with more than 30 values and a few others.
    public const string HUB = 'O03';
    public const string DLUGOSC = 'Długość';
    public const string WERSJA = 'Wersja';

    private const string DESCRIBED = 'Zestaw testowy';
    private const string OTHER_DESCRIPTION = 'Opis testowy';

    /** @var array<string, array{string, string|null}> id => name, parent */
    private const array TREE = [
        self::SPORT => ['Sport', null],
        self::TURYSTYKA => ['Turystyka', self::SPORT],
        self::PLECAKI => ['Plecaki', self::TURYSTYKA],
        self::ROWER => ['Rowery', self::SPORT],
        self::DOM => ['Dom', null],
        self::KUCHNIA => ['Kuchnia', self::DOM],
        self::DUZE => ['Duże', null],
        self::WEZEL => ['Węzeł', self::DUZE],
        self::INNE => ['Inne', self::DUZE],
    ];

    /** The partners of the hub axis in the overflow and the number of products of each (a tie at the tenth axis). */
    private const array PARTNERS = [
        'O02' => 5, 'O04' => 5, 'O05' => 4, 'O06' => 4, 'O07' => 3, 'O08' => 3, 'O09' => 2, 'O10' => 2, 'O11' => 2, 'O12' => 2,
    ];

    public static function matchesPhrase(ProductDocument $document): bool
    {
        return self::DESCRIBED === $document->shortDescription;
    }

    /**
     * The categories of the category facet: the children of the filter category, the roots without one, in tree order.
     *
     * @return list<string>
     */
    public static function facetCategories(?string $categoryId): array
    {
        return array_keys(array_filter(self::TREE, static fn (array $node): bool => $node[1] === $categoryId));
    }

    /**
     * @return list<ProductDocument>
     */
    public static function products(): array
    {
        return [...self::edgeCases(), ...self::overflow()];
    }

    /**
     * New versions of some products: other options, prices and availability, a variant dropped and one added, and a
     * product of the overflow that leaves the tie at the tenth axis.
     *
     * @return list<ProductDocument>
     */
    public static function changed(): array
    {
        $k = self::KOLOR;
        $r = self::ROZMIAR;

        return [
            self::product('k02', 'Namiot', [self::TURYSTYKA], false, [], [[[], 4000, true]], version: 2),
            self::product('k04', 'Buty A', [self::TURYSTYKA], true, [$k, $r], [[['zielony', '42'], 8000, true], [['biały', '43'], 12000, false]], version: 2),
            self::product('k05', 'Buty B', [self::TURYSTYKA], false, [$k, $r], [[['czarny', '43'], 15000, true], [['biały', '42'], 9000, true]], version: 2),
            self::product('k08', 'Rower', [self::ROWER], true, [self::KOLOR_RAMY, $r], [[['czarny', 'M'], 150000, true], [['czarny', 'L'], 139000, true], [['srebrny', 'M'], 140000, false]], version: 2),
            self::product('k10', 'Stolik', [self::KUCHNIA], true, [self::WYMIAR], [[['50.*'], 30000, false], [['@x'], 29000, true]], version: 2),
            self::product('k14', 'Skarpety', [self::TURYSTYKA, self::KUCHNIA], false, [$k], [[['czarny'], 1100, false], [['biały'], 1000, true], [['zielony'], 1200, true]], version: 2),
            self::product('o-O11-1', 'Duzy O11 1', [self::WEZEL], false, [self::HUB, 'O11'], [[['g', 'v1'], 5000, true]], version: 2),
            self::product('o-dl-3', 'Duzy dlugi 3', [self::WEZEL], true, [self::HUB, self::DLUGOSC], array_map(static fn (string $value): array => [['h', $value], 5500, true], ['d30', 'd31', 'd32', 'd35']), version: 2),
        ];
    }

    /**
     * The criteria of the comparison: every subset of up to three of the axes of the edge cases with one and with two
     * values of each axis, with and without a price range, availability, a category and the phrase; the criteria of the
     * overflow; and a few single cases (a number as the axis, an axis no product has, four axes, later pages).
     *
     * @return array<string, ProductSearchCriteria>
     */
    public static function criteria(): array
    {
        $values = [
            self::KOLOR => ['czarny', 'biały', 'Czarny', 'zielony', 'fioletowy'],
            self::ROZMIAR => ['42', '43', 'M', 'L'],
            self::KOLOR_RAMY => ['czarny', 'srebrny', 'złoty'],
            self::WYMIAR => ['50.*', 'a(b)\\c', '#1', '@x', '$9'],
            self::ZNAK => ["a\u{241F}b", "x\u{241E}y", 'Ż'],
        ];
        $optionSets = [];
        foreach (self::subsets(array_keys($values), 3) as $axes) {
            // Every axis with one value or with two: 2^count combinations.
            for ($mask = 0; $mask < 1 << \count($axes); ++$mask) {
                $optionSets[] = ['axes' => $axes, 'mask' => $mask];
            }
        }

        $prices = [[10000, 15000], [null, 9999], [14000, null]];
        $categories = [self::SPORT, self::TURYSTYKA, self::DOM, self::KUCHNIA];
        $criteria = [];
        $n = 0;
        foreach ($optionSets as $set) {
            foreach ([false, true] as $priced) {
                foreach ([false, true] as $available) {
                    foreach ([false, true] as $inCategory) {
                        foreach ([false, true] as $withPhrase) {
                            $options = [];
                            foreach ($set['axes'] as $position => $axis) {
                                $list = $values[$axis];
                                $start = ($n + $position) % \count($list);
                                $options[$axis] = 0 === ($set['mask'] >> $position & 1)
                                    ? [$list[$start]]
                                    : [$list[$start], $list[($start + 1) % \count($list)]];
                            }
                            $price = $priced ? $prices[$n % 3] : [null, null];
                            $criteria[] = self::criterion(
                                $options,
                                $price,
                                $available,
                                $inCategory ? $categories[$n % 4] : null,
                                $withPhrase,
                                $n,
                            );
                            ++$n;
                        }
                    }
                }
            }
        }

        foreach (self::overflowOptionSets() as $options) {
            foreach ([null, self::DUZE, self::WEZEL] as $category) {
                foreach ([false, true] as $priced) {
                    foreach ([false, true] as $available) {
                        foreach ([false, true] as $withPhrase) {
                            $criteria[] = self::criterion($options, $priced ? [2000, 6000] : [null, null], $available, $category, $withPhrase, $n++);
                        }
                    }
                }
            }
        }

        $single = [
            [[self::ROK => ['42']], [null, null], false, null],
            [[self::ROK => ['42'], self::KOLOR => ['czarny']], [null, null], false, null],
            [[self::MATERIAL => ['skóra', 'len']], [null, null], false, self::KUCHNIA],
            [[self::MATERIAL => ['skóra'], self::KOLOR => ['czarny']], [10000, 15000], true, null],
            [[self::SKALA => ['1:50']], [null, null], false, null],
            [[self::SKALA => ['1:50', '1:100'], self::ROZMIAR => ['42']], [null, null], false, self::DOM],
            [['Nieznana' => ['x']], [null, null], false, null],
            [['Nieznana' => ['x'], self::KOLOR => ['biały']], [null, 13000], false, null],
            [[self::KOLOR => ['czarny'], self::ROZMIAR => ['42'], self::KOLOR_RAMY => ['czarny'], self::WYMIAR => ['50.*']], [null, null], false, null],
        ];
        foreach ($single as [$options, $price, $available, $category]) {
            foreach ([false, true] as $withPhrase) {
                $criteria[] = self::criterion($options, $price, $available, $category, $withPhrase, $n++);
            }
        }
        $criteria[] = new ProductSearchCriteria(2, 4, options: [self::KOLOR => ['czarny', 'biały']], sort: ProductSort::PriceAsc, facetCategoryIds: self::facetCategories(null));
        $criteria[] = new ProductSearchCriteria(3, 7, categoryId: self::DUZE, sort: ProductSort::PriceDesc, facetCategoryIds: self::facetCategories(self::DUZE));
        $criteria[] = new ProductSearchCriteria(40, 4, phrase: self::PHRASE, facetCategoryIds: self::facetCategories(null));
        $criteria[] = new ProductSearchCriteria(1, 48, facetCategoryIds: self::facetCategories(null));

        $named = [];
        foreach ($criteria as $one) {
            $named[self::describe($one)] = $one;
        }

        return $named;
    }

    /**
     * The criteria as text, for the message of a difference.
     */
    public static function describe(ProductSearchCriteria $criteria): string
    {
        return json_encode([
            'page' => $criteria->page,
            'perPage' => $criteria->perPage,
            'category' => $criteria->categoryId,
            'phrase' => $criteria->phrase,
            'options' => $criteria->options,
            'price' => [$criteria->priceMin, $criteria->priceMax],
            'available' => $criteria->availableOnly,
            'sort' => $criteria->sort()->value,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<int|string, list<string>> $options
     * @param array{int|null, int|null}       $price
     */
    private static function criterion(array $options, array $price, bool $available, ?string $category, bool $withPhrase, int $n): ProductSearchCriteria
    {
        $sorts = $withPhrase ? [null, ProductSort::Name, ProductSort::PriceAsc, ProductSort::PriceDesc] : [ProductSort::Name, ProductSort::PriceAsc, ProductSort::PriceDesc];

        return new ProductSearchCriteria(
            1,
            4,
            $category,
            $withPhrase ? self::PHRASE : null,
            $options,
            $price[0],
            $price[1],
            $available,
            $sorts[$n % \count($sorts)],
            self::facetCategories($category),
        );
    }

    /**
     * @return list<array<string, list<string>>>
     */
    private static function overflowOptionSets(): array
    {
        return [
            [],
            [self::HUB => ['h']],
            [self::HUB => ['h', 'g']],
            ['O12' => ['v1']],
            [self::DLUGOSC => ['d33']],
            [self::DLUGOSC => ['d33', 'd99']],
            [self::DLUGOSC => ['d99']],
            [self::DLUGOSC => ['d34']],
            [self::HUB => ['h'], self::DLUGOSC => ['d02']],
            [self::HUB => ['h'], 'O12' => ['v2']],
            [self::HUB => ['g'], 'O02' => ['v1']],
            [self::HUB => ['h'], self::DLUGOSC => ['d31'], 'O02' => ['v1']],
            [self::WERSJA => ['w01']],
        ];
    }

    /**
     * @param list<string> $items
     *
     * @return list<list<string>> every subset of at most $max items, in the order of the items
     */
    private static function subsets(array $items, int $max): array
    {
        $subsets = [[]];
        foreach ($items as $item) {
            foreach ($subsets as $subset) {
                if (\count($subset) < $max) {
                    $subsets[] = [...$subset, $item];
                }
            }
        }

        return $subsets;
    }

    /**
     * @return list<ProductDocument>
     */
    private static function edgeCases(): array
    {
        $k = self::KOLOR;
        $r = self::ROZMIAR;
        $kr = self::KOLOR_RAMY;
        $w = self::WYMIAR;
        $z = self::ZNAK;

        return [
            // Without axes: the default variant, buyable, without a price, priced but not buyable.
            self::product('k01', 'Plecak', [self::PLECAKI], true, [], [[[], 5000, true]]),
            self::product('k02', 'Namiot', [self::TURYSTYKA], false, [], [[[], null, false]]),
            self::product('k03', 'Kurtka', [self::TURYSTYKA, self::KUCHNIA], true, [], [[[], 20000, false]]),
            // Two options in one variant (A) against the same options in two variants (B); an option on a variant
            // that cannot be bought next to a buyable one (b); the same name in another case (b, B), a value in
            // another case (D) and a variant without a price (D); many variants of one value (E).
            self::product('k04', 'Buty A', [self::TURYSTYKA], true, [$k, $r], [[['czarny', '42'], 10000, true], [['biały', '43'], 12000, true]]),
            self::product('k05', 'Buty B', [self::TURYSTYKA], false, [$k, $r], [[['czarny', '43'], 15000, true], [['biały', '42'], 9000, false]]),
            self::product('k06', 'buty b', [self::ROWER], true, [$k, $r], [[['czarny', '42'], 20000, false], [['czarny', '43'], 11000, true]]),
            self::product('k07', 'Buty D', [self::TURYSTYKA], false, [$k, $r], [[['Czarny', 'M'], null, false], [['zielony', 'M'], 13000, true]]),
            self::product('k17', 'Buty E', [self::TURYSTYKA], false, [$k, $r], [[['czarny', '42'], 9500, true], [['czarny', '43'], 9500, true], [['czarny', 'M'], 16000, true]]),
            // The same value on two axes, one axis the prefix of the other.
            self::product('k08', 'Rower', [self::ROWER], true, [$kr, $r], [[['czarny', 'M'], 150000, true], [['srebrny', 'M'], 140000, true], [['srebrny', 'L'], 145000, false]]),
            self::product('k09', 'Rower B', [self::ROWER], false, [$k, $kr], [[['czarny', 'srebrny'], 90000, true], [['biały', 'czarny'], 95000, false]]),
            // The characters of regular expressions and of the tokens in axes and values.
            self::product('k10', 'Stolik', [self::KUCHNIA], true, [$w], [[['50.*'], 30000, true], [['a(b)\\c'], 31000, true], [['#1'], 32000, false]]),
            self::product('k11', 'Lampa', [self::KUCHNIA, self::ROWER], false, [$w, $k], [[['@x', 'biały'], 12000, true], [['$9', 'czarny'], 14000, true], [['50.*', 'czarny'], null, false]]),
            self::product('k12', 'Kubek', [self::KUCHNIA], true, [$z, $r], [[["a\u{241F}b", '42'], 2000, true], [["x\u{241E}y", '43'], 2500, true], [["a\u{241F}b", '43'], 2600, false]]),
            self::product('k22', 'Kolorowanka', [self::DOM], true, [$z], [[['Ż'], 1000, true]]),
            // Polish letters, one axis, several categories.
            self::product('k13', 'Koszulka', [self::TURYSTYKA], true, [$k], [[['żółty'], 4000, true], [['zielony'], 4500, true]]),
            self::product('k14', 'Skarpety', [self::TURYSTYKA, self::KUCHNIA], false, [$k], [[['czarny'], 1000, true], [['biały'], 1000, true], [['zielony'], 1200, false]]),
            self::product('k16', 'Bidon', [self::ROWER, self::KUCHNIA], true, [$r], [[['M'], 1500, true], [['L'], 1600, true]]),
            // The axes in the other order.
            self::product('k18', 'Kask', [self::ROWER], true, [$r, $k], [[['42', 'biały'], 7000, true], [['43', 'czarny'], 7500, false]]),
            self::product('k19', 'Czapka', [self::DOM], true, [$k, $r], [[['biały', 'L'], 3500, true], [['zielony', 'L'], 3600, true], [['czarny', 'L'], 10000, true]]),
            // A number as the name of an axis, a Polish letter in an axis.
            self::product('k20', 'Rok', [self::DOM], false, [self::ROK], [[['42'], 3000, true]]),
            // An axis that begins with "@" and values with a colon.
            self::product('k23', 'Mapa', [self::DOM], true, [self::SKALA, $r], [[['1:50', '42'], 2200, true], [['1:100', '43'], 2300, false]]),
            self::product('k21', 'Torba', [self::KUCHNIA], false, [self::MATERIAL, $k], [[['skóra', 'czarny'], 12500, true], [['len', 'czarny'], 8000, false]]),
            // A draft: in the index, never in a listing (its offer is empty, so no price).
            self::product('k15', 'Szkic', [self::TURYSTYKA], true, [$k, $r], [[['czarny', '42'], null, false], [['biały', '43'], null, false]], ProductStatus::Draft),
        ];
    }

    /**
     * The hub axis O03 with value h is on every product of Węzeł; the other axis of a product is one of the partners
     * (Długość on 6 products, O02 and O04 on 5, ..., O09 to O12 on 2: a tie at the tenth and eleventh axis, with and
     * without O03 selected). Długość has 34 values: d01-d05 on 5 products, d06-d29 on 2, d30-d33 on 1 (a tie at the
     * thirtieth value), and d34 on 1 product of Inne with eight variants of the value (more variants than any other
     * value, fewer products). Wersja is on that product only, with eight values.
     *
     * @return list<ProductDocument>
     */
    private static function overflow(): array
    {
        $values = static fn (int $from, int $to): array => array_map(static fn (int $n): string => \sprintf('d%02d', $n), range($from, $to));
        $long = [
            1 => $values(1, 29),
            2 => $values(1, 29),
            3 => $values(30, 33),
            4 => $values(1, 5),
            5 => $values(1, 5),
            6 => $values(1, 5),
        ];
        $products = [];
        foreach ($long as $i => $list) {
            $variants = [];
            foreach ($list as $position => $value) {
                // Every seventh variant cannot be bought; the prices are 5000, 5500 or 6500.
                $variants[] = [['h', $value], 5000 + [0, 500, 1500][$position % 3], 0 !== $position % 7 || 0 === $position];
            }
            $products[] = self::product('o-dl-'.$i, 'Duzy dlugi '.$i, [self::WEZEL], 1 === $i % 2, [self::HUB, self::DLUGOSC], $variants);
        }
        foreach (self::PARTNERS as $axis => $count) {
            for ($i = 1; $i <= $count; ++$i) {
                $variants = [[['h', 'v1'], 5000, true], [['h', 'v2'], 7000, 0 === $i % 2]];
                if (1 === $i % 2) {
                    $variants[] = [['g', 'v1'], 4000, true];
                }
                $products[] = self::product(\sprintf('o-%s-%d', $axis, $i), \sprintf('Duzy %s %d', $axis, $i), [self::WEZEL], 0 === $i % 2, [self::HUB, $axis], $variants);
            }
        }
        $products[] = self::product('o-wiele', 'Duzy wielowariant', [self::INNE], true, [self::DLUGOSC, self::WERSJA], array_map(
            static fn (int $n): array => [['d34', \sprintf('w%02d', $n)], 3000, true],
            range(1, 8),
        ));

        return $products;
    }

    /**
     * @param list<string>                              $categories the main category first
     * @param list<string>                              $axes
     * @param list<array{list<string>, int|null, bool}> $variants   values, gross, purchasable
     */
    private static function product(string $key, string $name, array $categories, bool $described, array $axes, array $variants, ProductStatus $status = ProductStatus::Published, int $version = 1): ProductDocument
    {
        self::assertTheRulesOfTheDomain($key, $axes, $variants);

        $ancestors = [];
        $names = [];
        foreach ($categories as $category) {
            foreach (self::path($category) as $node) {
                if (!\in_array($node, $ancestors, true)) {
                    $ancestors[] = $node;
                    $names[] = self::TREE[$node][0];
                }
            }
        }
        $documents = [];
        $fromGross = null;
        foreach ($variants as [$values, $gross, $purchasable]) {
            $documents[] = new VariantDocument($gross, $purchasable, array_map(
                static fn (string $axis, string $value): string => $axis.VariantDocument::OPTION_SEPARATOR.$value,
                $axes,
                $values,
            ));
            if ($purchasable) {
                $fromGross = min($fromGross ?? $gross, $gross);
            }
        }

        return new ProductDocument(
            self::id($key),
            $version,
            $name,
            'produkt-'.strtolower($key),
            $described ? self::DESCRIBED : self::OTHER_DESCRIPTION,
            $categories[0],
            implode('/', self::path($categories[0])),
            $categories,
            $ancestors,
            null,
            $names,
            $documents,
            $fromGross,
            $status,
        );
    }

    /**
     * The catalog goes through the rules of Catalog (VariantAxes, VariantValues, ProductVariants) unchanged, and a
     * variant that can be bought has a price (the rule of Cart).
     *
     * @param list<string>                              $axes
     * @param list<array{list<string>, int|null, bool}> $variants
     */
    private static function assertTheRulesOfTheDomain(string $key, array $axes, array $variants): void
    {
        $productVariants = [];
        foreach ($variants as $position => [$values, $gross, $purchasable]) {
            $variantValues = VariantValues::fromList($values);
            if ($variantValues->toList() !== $values) {
                throw new \LogicException(\sprintf('The values of a variant of %s change under the rule of texts.', $key));
            }
            if ($purchasable && null === $gross) {
                throw new \LogicException(\sprintf('A variant of %s can be bought without a price.', $key));
            }
            $productVariants[] = new ProductVariant(VariantId::fromString(self::id($key.'-v'.$position)), $variantValues);
        }
        $variantAxes = VariantAxes::fromList($axes);
        if ($variantAxes->names() !== $axes) {
            throw new \LogicException(\sprintf('The axes of %s change under the rule of texts.', $key));
        }
        ProductVariants::create($variantAxes, $productVariants);
    }

    /**
     * @return list<string> the ids from the root to the category
     */
    private static function path(string $category): array
    {
        $path = [];
        for ($node = $category; null !== $node; $node = self::TREE[$node][1]) {
            array_unshift($path, $node);
        }

        return $path;
    }

    /**
     * A stable id of a key: the products sort by it among equal names.
     */
    private static function id(string $key): string
    {
        $hex = substr(hash('sha256', $key), 0, 12);

        return '0198a1f0-0000-7000-8000-'.$hex;
    }
}
