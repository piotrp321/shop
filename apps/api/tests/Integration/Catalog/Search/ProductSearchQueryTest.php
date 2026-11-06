<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\OptionFacet;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\ProductSort;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use App\Tests\Factory\ProductDocumentFactory;
use PHPUnit\Framework\TestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Phrase, variant filters, sorting and facets of the real Elasticsearch on a temporary index.
 */
final class ProductSearchQueryTest extends TestCase
{
    use Factories;
    use UsesTemporarySearchAlias;

    private const string ROOT = '00000000-0000-4000-8000-0000000000a1';
    private const string CHILD_A = '00000000-0000-4000-8000-0000000000a2';
    private const string CHILD_B = '00000000-0000-4000-8000-0000000000a3';
    private const string LEAF = '00000000-0000-4000-8000-0000000000a4';

    protected function setUp(): void
    {
        $this->maintenance()->setup();
    }

    // ---- phrase ----

    public function testInflectedPhraseFindsTheProduct(): void
    {
        $this->index(self::product('Kurtka puchowa'), self::product('Plecak'));

        self::assertSame(['Kurtka puchowa'], $this->names($this->phrase('kurtki')));
        self::assertSame(['Kurtka puchowa'], $this->names($this->phrase('KURTKĄ')));
    }

    public function testPhraseWithoutPolishCharactersFindsTheProduct(): void
    {
        $this->index(self::product('Śpiwór zimowy'), self::product('Plecak'));

        self::assertSame(['Śpiwór zimowy'], $this->names($this->phrase('spiwor')));
        self::assertSame(['Śpiwór zimowy'], $this->names($this->phrase('spiwory')));
    }

    public function testPhraseWithATypoFindsTheProduct(): void
    {
        $this->index(self::product('Plecak turystyczny'), self::product('Buty szosowe Tarvik Asfalt 200'));

        self::assertSame(['Plecak turystyczny'], $this->names($this->phrase('plecal')));
        self::assertSame(['Buty szosowe Tarvik Asfalt 200'], $this->names($this->phrase('asfalt')));
        self::assertSame(['Buty szosowe Tarvik Asfalt 200'], $this->names($this->phrase('asfalr')));
    }

    public function testTwoWordsNeedBothToMatch(): void
    {
        $this->index(self::product('Kurtka puchowa'), self::product('Kurtka letnia'));

        self::assertSame(['Kurtka puchowa'], $this->names($this->phrase('kurtka puchowa')));
        self::assertSame([], $this->names($this->phrase('kurtka xyzzy')));
        self::assertSame([], $this->names($this->phrase('kurtkq xyzzy')));
        self::assertSame([], $this->names($this->phrase('kurtka puchowy xyzzy')));
    }

    public function testTwoWordsMayBeInDifferentFields(): void
    {
        $this->index(
            self::product('Kurtka puchowa', categoryNames: ['Turystyka', 'Odzież']),
            self::product('Kurtka letnia', categoryNames: ['Bieganie']),
            self::product('Czapka', description: 'Pasuje do kurtki zimowej'),
        );

        self::assertSame(['Kurtka puchowa'], $this->names($this->phrase('kurtka turystyka')));
        self::assertSame(['Kurtka puchowa'], $this->names($this->phrase('kurtka odziez')));
    }

    public function testWordWithoutPolishCharactersTogetherWithAWordOnlyInTheDescription(): void
    {
        $this->index(
            self::product('Śpiwór zimowy', description: 'Bardzo ciepły i lekki'),
            self::product('Śpiwór letni', description: 'Cienki'),
        );

        self::assertSame(['Śpiwór zimowy'], $this->names($this->phrase('spiwor cieply')));
        self::assertSame(['Śpiwór zimowy'], $this->names($this->phrase('spiwor ciepły')));
    }

    public function testNameHitRanksAboveDescriptionHit(): void
    {
        $this->index(
            self::product('Latarka', description: 'Świeci jak kask rowerowy'),
            self::product('Kask rowerowy', description: 'Lekki'),
        );

        self::assertSame(['Kask rowerowy', 'Latarka'], $this->names($this->phrase('kask')));
    }

    public function testExactHitRanksAboveATypo(): void
    {
        $this->index(self::product('Plecek miejski'), self::product('Plecak miejski'));

        self::assertSame(['Plecak miejski', 'Plecek miejski'], $this->names($this->phrase('plecak')));
    }

    public function testRelevanceTiesBreakByNameThenId(): void
    {
        $this->index(self::product('Kask B', id: '0198a1f0-0000-7000-8000-000000000002'), self::product('Kask B', id: '0198a1f0-0000-7000-8000-000000000001'), self::product('Kask A'));

        $items = $this->phrase('kask')->items;

        self::assertSame(['Kask A', 'Kask B', 'Kask B'], array_map(static fn (ProductDocument $d): string => $d->name, $items));
        self::assertSame(['0198a1f0-0000-7000-8000-000000000001', '0198a1f0-0000-7000-8000-000000000002'], [$items[1]->id, $items[2]->id]);
    }

    public function testPhraseCombinesWithTheCategoryFilter(): void
    {
        $this->index(
            self::product('Kask rowerowy', categoryAncestors: [self::ROOT, self::CHILD_A]),
            self::product('Kask narciarski', categoryAncestors: [self::ROOT, self::CHILD_B]),
        );

        self::assertSame(['Kask rowerowy'], $this->names($this->search(new ProductSearchCriteria(1, 24, categoryId: self::CHILD_A, phrase: 'kask'))));
    }

    public function testPhraseWithQueryOperatorsIsAPlainPhrase(): void
    {
        $this->index(self::product('Kask rowerowy'));

        self::assertSame([], $this->names($this->phrase('kask OR name:* AND "x"')));
        self::assertSame([], $this->names($this->phrase('*')));
    }

    // ---- variant filter ----

    public function testTwoOptionsMustBelongToOneVariant(): void
    {
        $this->indexShoes();

        $found = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne'], 'Rozmiar' => ['42']]));

        self::assertSame(['Buty A', 'Buty C', 'Buty E'], $this->names($found));
        self::assertSame(3, $found->total);
    }

    public function testSeveralValuesOfOneAxisMeanAnyOfThem(): void
    {
        $this->indexShoes();

        self::assertSame(
            ['Buty A', 'Buty B', 'Buty C', 'Buty E'],
            $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne', 'białe'], 'Rozmiar' => ['42']]))),
        );
        self::assertSame(['Buty A', 'Buty B'], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['białe']]))));
    }

    public function testOptionValuesAreMatchedExactlyIncludingCase(): void
    {
        $this->indexShoes();

        self::assertSame([], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['kolor' => ['czarne']]))));
        self::assertSame([], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['Czarne']]))));
        self::assertSame([], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Rozmiar' => ['czarne']]))));
    }

    public function testAvailabilityAndPriceHoldForTheSameVariantAsTheOptions(): void
    {
        $this->indexShoes();

        // Buty B: the black variant (43) is buyable, the 42 variant is white and not buyable; Buty C: black 42 is not buyable.
        self::assertSame(['Buty A'], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne'], 'Rozmiar' => ['42']], availableOnly: true))));
        self::assertSame(['Buty A', 'Buty B'], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne']], availableOnly: true))));
        // Black and 42 and priced 90–110 zł: only Buty A; Buty B's 42 variant costs 90 zł but is white.
        self::assertSame(['Buty A'], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne'], 'Rozmiar' => ['42']], priceMin: 9000, priceMax: 11000))));
        self::assertSame(['Buty B'], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne'], 'Rozmiar' => ['43']], priceMin: 14000))));
    }

    public function testPriceRangeIsInclusiveAndUsesBuyableVariantsOnly(): void
    {
        $this->indexShoes();

        self::assertSame(['Buty A', 'Plecak'], $this->names($this->search(new ProductSearchCriteria(1, 24, priceMax: 10000))));
        self::assertSame(['Buty A'], $this->names($this->search(new ProductSearchCriteria(1, 24, priceMin: 10000, priceMax: 10000))));
        // The only variant of Buty C that costs 200 zł is not buyable; Buty D has a buyable one at 120 zł.
        self::assertSame(['Buty A', 'Buty D'], $this->names($this->search(new ProductSearchCriteria(1, 24, priceMin: 10001, priceMax: 12000))));
    }

    public function testAvailableOnlyDropsProductsWithoutABuyableVariant(): void
    {
        $this->indexShoes();

        $found = $this->search(new ProductSearchCriteria(1, 24, availableOnly: true));

        self::assertSame(['Buty A', 'Buty B', 'Buty D', 'Plecak'], $this->names($found));
        self::assertSame(4, $found->total);
    }

    public function testProductWithoutABuyableVariantStaysInTheListWithoutVariantConditions(): void
    {
        $this->indexShoes();

        self::assertSame(['Buty A', 'Buty B', 'Buty C', 'Buty D', 'Buty E', 'Plecak'], $this->names($this->search(new ProductSearchCriteria(1, 24))));
    }

    public function testOptionWithRegexCharactersIsAPlainValue(): void
    {
        $this->index(self::product('Stolik', variants: [self::variant(['Wymiar (cm)' => '50.*'], 100, true)]), self::product('Ławka', variants: [self::variant(['Wymiar (cm)' => '500'], 100, true)]));

        self::assertSame(['Stolik'], $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Wymiar (cm)' => ['50.*']]))));
    }

    public function testNumericAxisAndValueNamesWork(): void
    {
        $this->index(self::product('Rama', variants: [self::variant(['2024' => '42'], 100, true)]), self::product('Inna', variants: [self::variant(['2024' => '43'], 100, true)]));

        $result = $this->search(new ProductSearchCriteria(1, 24, options: ['2024' => ['42']]));

        self::assertSame(['Rama'], $this->names($result));
        self::assertSame(1, $result->facets->options[0]->values[0]->count);
        self::assertSame('2024', $result->facets->options[0]->axis);
        self::assertSame(['42', '43'], array_map(static fn ($v): string => $v->value, $result->facets->options[0]->values));
    }

    // ---- sorting ----

    public function testSortsByPriceAscendingWithProductsWithoutPriceLast(): void
    {
        $this->indexShoes();

        self::assertSame(
            ['Plecak', 'Buty A', 'Buty D', 'Buty B', 'Buty C', 'Buty E'],
            $this->names($this->search(new ProductSearchCriteria(1, 24, sort: ProductSort::PriceAsc))),
        );
    }

    public function testSortsByPriceDescendingWithProductsWithoutPriceLast(): void
    {
        $this->indexShoes();

        self::assertSame(
            ['Buty B', 'Buty D', 'Buty A', 'Plecak', 'Buty C', 'Buty E'],
            $this->names($this->search(new ProductSearchCriteria(1, 24, sort: ProductSort::PriceDesc))),
        );
    }

    public function testPriceTiesBreakByNameThenId(): void
    {
        $this->index(
            self::product('Bbb', fromGross: 500, id: '0198a1f0-0000-7000-8000-000000000002'),
            self::product('Bbb', fromGross: 500, id: '0198a1f0-0000-7000-8000-000000000001'),
            self::product('Aaa', fromGross: 500),
            self::product('Ccc', fromGross: 100),
        );

        $items = $this->search(new ProductSearchCriteria(1, 24, sort: ProductSort::PriceAsc))->items;

        self::assertSame(['Ccc', 'Aaa', 'Bbb', 'Bbb'], array_map(static fn (ProductDocument $d): string => $d->name, $items));
        self::assertSame(['0198a1f0-0000-7000-8000-000000000001', '0198a1f0-0000-7000-8000-000000000002'], [$items[2]->id, $items[3]->id]);
    }

    public function testSortsByNameWithAPhrase(): void
    {
        $this->index(self::product('Kask B'), self::product('Kask A', description: 'kask kask kask'));

        self::assertSame(['Kask A', 'Kask B'], $this->names($this->search(new ProductSearchCriteria(1, 24, phrase: 'kask', sort: ProductSort::Name))));
    }

    public function testPriceSortIgnoresTheVariantFilter(): void
    {
        $this->indexShoes();

        // Buty A (from 100 zł) before Buty B (from 150 zł) although the filtered variant of A costs more.
        self::assertSame(
            ['Buty A', 'Buty B'],
            $this->names($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne', 'białe']], availableOnly: true, sort: ProductSort::PriceAsc))),
        );
    }

    // ---- facets ----

    public function testOptionFacetsCountTheTotalAfterAddingTheValue(): void
    {
        $this->indexShoes();

        $facets = $this->search(new ProductSearchCriteria(1, 24))->facets;

        self::assertSame(['Kolor' => ['czarne' => 4, 'białe' => 2], 'Rozmiar' => ['42' => 4, '43' => 2], 'Wymiar' => ['x' => 1, 'y' => 1]], $this->options($facets->options));
        foreach (['Kolor' => ['czarne', 'białe'], 'Rozmiar' => ['42', '43']] as $axis => $values) {
            foreach ($values as $value) {
                $total = $this->search(new ProductSearchCriteria(1, 24, options: [$axis => [$value]]))->total;
                self::assertSame($total, $this->options($facets->options)[$axis][$value], $axis.':'.$value);
            }
        }
    }

    public function testOptionFacetOfAnotherAxisCountsWithTheSelectedOptions(): void
    {
        $this->indexShoes();

        $facets = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne']]))->facets;

        $options = $this->options($facets->options);
        self::assertSame(['42' => 3, '43' => 1], $options['Rozmiar']);
        foreach (['42', '43'] as $size) {
            self::assertSame($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne'], 'Rozmiar' => [$size]]))->total, $options['Rozmiar'][$size]);
        }
        // The axis of the selection is counted without its own condition: white shoes are still offered.
        self::assertSame(['czarne' => 4, 'białe' => 2], $options['Kolor']);
    }

    public function testSelectedValueStaysInTheListWithZero(): void
    {
        $this->indexShoes();

        $facets = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['zielone'], 'Rozmiar' => ['42']]))->facets;

        $options = $this->options($facets->options);
        self::assertSame(0, $options['Kolor']['zielone']);
        self::assertSame('zielone', array_key_last($options['Kolor']));
        // Counted without the condition on Kolor, with the one on Rozmiar: black 42 in A, C, E; white 42 in B.
        self::assertSame(['czarne' => 3, 'białe' => 1, 'zielone' => 0], $options['Kolor']);
    }

    public function testSelectedAxisAbsentFromTheResultsIsListedWithItsSelectedValuesAtZero(): void
    {
        $this->indexShoes();

        $facets = $this->search(new ProductSearchCriteria(1, 24, options: ['Materiał' => ['skóra']]))->facets;

        self::assertSame(0, $this->options($facets->options)['Materiał']['skóra']);
    }

    public function testOptionFacetsRespectPriceAndAvailability(): void
    {
        $this->indexShoes();

        $facets = $this->search(new ProductSearchCriteria(1, 24, availableOnly: true))->facets;

        // Buyable variants: A black 42 and white 43, B black 43, D (below). Buty C and E have none.
        $options = $this->options($facets->options);
        self::assertSame($this->search(new ProductSearchCriteria(1, 24, availableOnly: true, options: ['Kolor' => ['czarne']]))->total, $options['Kolor']['czarne']);
        self::assertSame($this->search(new ProductSearchCriteria(1, 24, availableOnly: true, options: ['Rozmiar' => ['42']]))->total, $options['Rozmiar']['42']);
        self::assertSame($this->search(new ProductSearchCriteria(1, 24, priceMin: 14000, options: ['Rozmiar' => ['43']]))->total, $this->options($this->search(new ProductSearchCriteria(1, 24, priceMin: 14000))->facets->options)['Rozmiar']['43']);
    }

    public function testOptionFacetsSeeThePhraseAndTheCategoryButNotTheVariantConditionOnTheAxisList(): void
    {
        $this->index(
            self::product('Buty Alfa', categoryAncestors: [self::ROOT, self::CHILD_A], variants: [self::variant(['Kolor' => 'czarne'], 100, true)]),
            self::product('Buty Beta', categoryAncestors: [self::ROOT, self::CHILD_B], variants: [self::variant(['Kolor' => 'zielone'], 100, true)]),
            self::product('Kask', categoryAncestors: [self::ROOT, self::CHILD_A], variants: [self::variant(['Kolor' => 'biały'], 100, true)]),
        );

        $facets = $this->search(new ProductSearchCriteria(1, 24, categoryId: self::CHILD_A, phrase: 'buty'))->facets;

        self::assertSame(['Kolor' => ['czarne' => 1]], $this->options($facets->options));
    }

    public function testAxesAreOrderedByProductsAndValuesByCountThenValue(): void
    {
        $this->index(
            self::product('A', variants: [self::variant(['Kolor' => 'zielone', 'Rozmiar' => 'M'], 100, true)]),
            self::product('B', variants: [self::variant(['Kolor' => 'biały', 'Rozmiar' => 'M'], 100, true)]),
            self::product('C', variants: [self::variant(['Kolor' => 'biały', 'Rozmiar' => 'L'], 100, true)]),
            self::product('D', variants: [self::variant(['Kolor' => 'czarne'], 100, true)]),
            self::product('E', variants: [self::variant(['Materiał' => 'bawełna'], 100, true)]),
        );

        $options = $this->search(new ProductSearchCriteria(1, 24))->facets->options;

        // Kolor: 4 products, Rozmiar: 3, Materiał: 1.
        self::assertSame(['Kolor', 'Rozmiar', 'Materiał'], array_map(static fn (OptionFacet $facet): string => $facet->axis, $options));
        self::assertSame(['biały' => 2, 'czarne' => 1, 'zielone' => 1], $this->options($options)['Kolor']);
        self::assertSame(['M' => 2, 'L' => 1], $this->options($options)['Rozmiar']);
    }

    public function testAxesAreLimitedToTenAndValuesToThirtyPerAxis(): void
    {
        $variants = [];
        for ($axis = 1; $axis <= 12; ++$axis) {
            $variants[] = self::variant([\sprintf('Os %02d', $axis) => 'x'], 100, true);
        }
        $many = [];
        for ($value = 1; $value <= 35; ++$value) {
            $many[] = self::variant(['Wiele' => \sprintf('w%02d', $value)], 100, true);
        }
        $this->index(self::product('Osie', variants: $variants), self::product('Wartości', variants: $many), self::product('Wartości 2', variants: $many));

        $options = $this->search(new ProductSearchCriteria(1, 24))->facets->options;

        self::assertCount(10, $options);
        self::assertSame('Wiele', $options[0]->axis);
        self::assertCount(30, $options[0]->values);
        self::assertSame('w01', $options[0]->values[0]->value);
        self::assertSame('w30', $options[0]->values[29]->value);
    }

    public function testSelectedAxesAreListedEvenBeyondTheTenthAxis(): void
    {
        $variants = [];
        for ($axis = 1; $axis <= 12; ++$axis) {
            $variants[] = self::variant([\sprintf('Os %02d', $axis) => 'x'], 100, true);
        }
        $this->index(self::product('Osie', variants: $variants));

        $options = $this->search(new ProductSearchCriteria(1, 24, options: ['Os 12' => ['x']]))->facets->options;

        self::assertContains('Os 12', array_map(static fn (OptionFacet $facet): string => $facet->axis, $options));
        self::assertSame(1, $this->options($options)['Os 12']['x']);
    }

    public function testSelectedValueBeyondTheThirtiethStillHasItsRealCount(): void
    {
        $many = [];
        for ($value = 1; $value <= 35; ++$value) {
            $many[] = self::variant(['Wiele' => \sprintf('w%02d', $value)], 100, true);
        }
        $this->index(self::product('Wartości', variants: $many));

        $options = $this->search(new ProductSearchCriteria(1, 24, options: ['Wiele' => ['w35']]))->facets->options;

        self::assertSame(1, $this->options($options)['Wiele']['w35']);
    }

    public function testPriceFacetUsesBuyableVariantsMatchingTheOptionsWithoutThePriceCondition(): void
    {
        $this->indexShoes();

        $all = $this->search(new ProductSearchCriteria(1, 24))->facets->price;
        self::assertNotNull($all);
        // Buyable: A 100 and 120, B 150, D 120 and 130, Plecak 50.
        self::assertSame([5000, 15000], [$all->min, $all->max]);

        $black = $this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne']], priceMin: 99999))->facets->price;
        self::assertNotNull($black);
        self::assertSame([10000, 15000], [$black->min, $black->max]);
    }

    public function testPriceFacetIsNullWithoutABuyableVariant(): void
    {
        $this->index(self::product('Buty C', variants: [self::variant(['Kolor' => 'czarne'], 20000, false)]), self::product('Bez ceny'));

        self::assertNull($this->search(new ProductSearchCriteria(1, 24))->facets->price);
        self::assertNull($this->search(new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne']]))->facets->price);
    }

    public function testAvailableCountIsTheTotalAfterAddingAvailability(): void
    {
        $this->indexShoes();

        foreach ([
            new ProductSearchCriteria(1, 24),
            new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne']]),
            new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne'], 'Rozmiar' => ['42']]),
            new ProductSearchCriteria(1, 24, priceMin: 11000),
            new ProductSearchCriteria(1, 24, phrase: 'buty'),
            new ProductSearchCriteria(1, 24, availableOnly: true),
        ] as $criteria) {
            $withAvailability = new ProductSearchCriteria(1, 24, $criteria->categoryId, $criteria->phrase, $criteria->options, $criteria->priceMin, $criteria->priceMax, true);
            self::assertSame($this->search($withAvailability)->total, $this->search($criteria)->facets->availableCount);
        }
        self::assertSame(4, $this->search(new ProductSearchCriteria(1, 24))->facets->availableCount);
    }

    public function testFacetsDoNotDependOnThePage(): void
    {
        $this->indexShoes();

        $first = $this->search(new ProductSearchCriteria(1, 2))->facets;
        $beyond = $this->search(new ProductSearchCriteria(99, 2))->facets;

        self::assertEquals($first, $beyond);
    }

    public function testCategoryFacetCountsTheRequestedCategoriesWithTheVariantCondition(): void
    {
        $this->index(
            self::product('Czarne A', categoryAncestors: [self::ROOT, self::CHILD_A, self::LEAF], variants: [self::variant(['Kolor' => 'czarne'], 100, true)]),
            self::product('Czarne B', categoryAncestors: [self::ROOT, self::CHILD_A], variants: [self::variant(['Kolor' => 'czarne'], 100, true)]),
            self::product('Białe', categoryAncestors: [self::ROOT, self::CHILD_B], variants: [self::variant(['Kolor' => 'białe'], 100, true)]),
            self::product('Zielone', categoryAncestors: [self::ROOT, self::CHILD_B], variants: [self::variant(['Kolor' => 'zielone'], 100, true)]),
        );

        $all = $this->search(new ProductSearchCriteria(1, 24, categoryId: self::ROOT, facetCategoryIds: [self::CHILD_A, self::CHILD_B]))->facets->categoryCounts;
        self::assertSame([self::CHILD_A => 2, self::CHILD_B => 2], $all);

        $black = $this->search(new ProductSearchCriteria(1, 24, categoryId: self::ROOT, options: ['Kolor' => ['czarne']], facetCategoryIds: [self::CHILD_A, self::CHILD_B]))->facets->categoryCounts;
        self::assertSame([self::CHILD_A => 2], $black);

        $deeper = $this->search(new ProductSearchCriteria(1, 24, categoryId: self::CHILD_A, facetCategoryIds: [self::LEAF]))->facets->categoryCounts;
        self::assertSame([self::LEAF => 1], $deeper);
    }

    public function testCategoryFacetSeesThePhraseAndIsEmptyWithoutRequestedCategories(): void
    {
        $this->index(
            self::product('Kask', categoryAncestors: [self::ROOT, self::CHILD_A]),
            self::product('Buty', categoryAncestors: [self::ROOT, self::CHILD_B]),
        );

        self::assertSame([self::CHILD_B => 1], $this->search(new ProductSearchCriteria(1, 24, phrase: 'buty', facetCategoryIds: [self::CHILD_A, self::CHILD_B]))->facets->categoryCounts);
        self::assertSame([], $this->search(new ProductSearchCriteria(1, 24, facetCategoryIds: []))->facets->categoryCounts);
    }

    public function testTotalIncludesTheVariantFilterAndPagesAreCutAfterIt(): void
    {
        $this->indexShoes();

        $second = $this->search(new ProductSearchCriteria(2, 2, priceMax: 20000));

        self::assertSame(4, $second->total);
        self::assertCount(2, $second->items);
    }

    public function testEmptyIndexGivesEmptyFacets(): void
    {
        $result = $this->search(new ProductSearchCriteria(1, 24, phrase: 'kask', options: ['Kolor' => ['czarne']]));

        self::assertSame(0, $result->total);
        self::assertSame(0, $result->facets->availableCount);
        self::assertNull($result->facets->price);
        self::assertSame(0, $this->options($result->facets->options)['Kolor']['czarne']);
    }

    // ---- fixtures and helpers ----

    /**
     * A: black 42 buyable 100 zł, white 43 buyable 120 zł; B: black 43 buyable 150 zł, white 42 not buyable 90 zł;
     * C: black 42 not buyable 200 zł; D: buyable 120 zł and 130 zł (axis Kolor: zielone/szare, size 44); E: black 42 without price;
     * Plecak without axes, buyable 50 zł.
     */
    private function indexShoes(): void
    {
        $this->index(
            self::product('Buty A', variants: [self::variant(['Kolor' => 'czarne', 'Rozmiar' => '42'], 10000, true), self::variant(['Kolor' => 'białe', 'Rozmiar' => '43'], 12000, true)], fromGross: 10000),
            self::product('Buty B', variants: [self::variant(['Kolor' => 'czarne', 'Rozmiar' => '43'], 15000, true), self::variant(['Kolor' => 'białe', 'Rozmiar' => '42'], 9000, false)], fromGross: 15000),
            self::product('Buty C', variants: [self::variant(['Kolor' => 'czarne', 'Rozmiar' => '42'], 20000, false)]),
            self::product('Buty D', variants: [self::variant(['Wymiar' => 'x'], 12000, true), self::variant(['Wymiar' => 'y'], 13000, true)], fromGross: 12000),
            self::product('Buty E', variants: [self::variant(['Kolor' => 'czarne', 'Rozmiar' => '42'], null, false)]),
            self::product('Plecak', variants: [self::variant([], 5000, true)], fromGross: 5000),
        );
    }

    private function phrase(string $phrase): SearchResult
    {
        return $this->search(new ProductSearchCriteria(1, 24, phrase: $phrase));
    }

    private function search(ProductSearchCriteria $criteria): SearchResult
    {
        return (new ElasticsearchProductSearch(self::clientFactory()->create(2.0), $this->alias()))->search($criteria);
    }

    /**
     * @return list<string>
     */
    private function names(SearchResult $result): array
    {
        return array_map(static fn (ProductDocument $d): string => $d->name, $result->items);
    }

    /**
     * @param list<OptionFacet> $facets
     *
     * @return array<string, array<int|string, int>> numeric values are int keys in PHP
     */
    private function options(array $facets): array
    {
        $options = [];
        foreach ($facets as $facet) {
            foreach ($facet->values as $value) {
                $options[$facet->axis][$value->value] = $value->count;
            }
        }

        return $options;
    }

    private function index(ProductDocument ...$documents): void
    {
        $indexer = new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
        foreach ($documents as $document) {
            $indexer->index($document);
        }
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    /**
     * @param array<int|string, string> $options axis => value
     */
    private static function variant(array $options, ?int $gross, bool $purchasable): VariantDocument
    {
        // PHP turns a numeric axis name into an int key.
        $axes = array_map(strval(...), array_keys($options));

        return new VariantDocument(
            $gross,
            $purchasable,
            $axes,
            array_map(static fn (string $axis, string $value): string => $axis.VariantDocument::OPTION_SEPARATOR.$value, $axes, $options),
        );
    }

    /**
     * @param list<string>          $categoryNames
     * @param list<string>          $categoryAncestors
     * @param list<VariantDocument> $variants
     */
    private static function product(
        string $name,
        string $description = 'Opis',
        array $categoryNames = [],
        array $categoryAncestors = [self::ROOT],
        array $variants = [],
        ?int $fromGross = null,
        ?string $id = null,
    ): ProductDocument {
        return ProductDocumentFactory::createOne([
            ...(null === $id ? [] : ['id' => $id]),
            'name' => $name,
            'shortDescription' => $description,
            'mainCategoryId' => end($categoryAncestors),
            'categoryIds' => [end($categoryAncestors)],
            'categoryAncestorIds' => $categoryAncestors,
            'categoryNames' => $categoryNames,
            'variants' => $variants,
            'fromGross' => $fromGross,
        ]);
    }
}
