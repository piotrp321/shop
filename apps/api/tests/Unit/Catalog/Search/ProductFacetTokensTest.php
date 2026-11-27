<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ProductFacetTokens;
use App\Shared\Domain\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tokens of a product for the facets of the search index (spec search, M1, "Dokument"): the syntax, which variants
 * feed which field, and the refusal of a product the model cannot count correctly.
 */
final class ProductFacetTokensTest extends TestCase
{
    private const string ID = '0198a1f0-0000-7000-8000-000000000001';
    private const string US = "\u{1F}";
    private const string RS = "\u{1E}";

    public function testTheSeparatorsAreTheOnesTheRuleOfTextsForbids(): void
    {
        // A name or a value cannot contain them, so a token is read without ambiguity.
        self::assertSame("\u{1E}", ProductFacetTokens::PAIR_SEPARATOR);
        self::assertSame("\u{1F}", VariantDocument::OPTION_SEPARATOR);
        self::assertTrue(Text::hasForbiddenCharacters('a'.ProductFacetTokens::PAIR_SEPARATOR.'b'));
        self::assertTrue(Text::hasForbiddenCharacters('a'.VariantDocument::OPTION_SEPARATOR.'b'));
    }

    public function testAProductWithTwoAxesHasTheTokensOfTheSpec(): void
    {
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(18_900, false, ['Kolor' => 'czarne', 'Rozmiar' => '42']),
            self::variant(19_900, true, ['Kolor' => 'Białe', 'Rozmiar' => '43']),
            self::variant(null, false, ['Kolor' => 'zielone', 'Rozmiar' => '44']),
        ]));

        self::assertEqualsCanonicalizing([
            '#p', '#c', '@Kolor', '@Rozmiar', '$',
            'Kolor'.self::US.'czarne', 'Rozmiar'.self::US.'42',
            'Kolor'.self::US.'Białe', 'Rozmiar'.self::US.'43',
            'Kolor'.self::US.'zielone', 'Rozmiar'.self::US.'44',
        ], $tokens->facets);
        // Only the purchasable variant: no black, no green, no "$".
        self::assertEqualsCanonicalizing([
            '#p', '#c', '@Kolor', '@Rozmiar',
            'Kolor'.self::US.'Białe', 'Rozmiar'.self::US.'43',
        ], $tokens->buyFacets);
        self::assertTrue($tokens->purchasable);
        self::assertSame([19_900], $tokens->buyPrices);
        self::assertSame(19_900, $tokens->maxGross);
        self::assertSame([['Kolor', 'Rozmiar'], ['Kolor', 'Rozmiar'], ['Kolor', 'Rozmiar']], $tokens->variantAxes);
    }

    public function testAVariantWithTwoOptionsHasFourPairTokensAndTheBuyOnesComeFromPurchasableVariantsOnly(): void
    {
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(18_900, false, ['Kolor' => 'czarne', 'Rozmiar' => '42']),
            self::variant(19_900, true, ['Kolor' => 'Białe', 'Rozmiar' => '43']),
        ]));

        $black = 'Kolor'.self::US.'czarne';
        $size42 = 'Rozmiar'.self::US.'42';
        $white = 'Kolor'.self::US.'Białe';
        $size43 = 'Rozmiar'.self::US.'43';
        $buy = [
            $white.self::RS.$size43, $size43.self::RS.$white,
            $white.self::RS.'@Rozmiar', $size43.self::RS.'@Kolor',
        ];
        self::assertEqualsCanonicalizing($buy, $tokens->pairBuy);
        self::assertEqualsCanonicalizing([
            ...$buy,
            $black.self::RS.$size42, $size42.self::RS.$black,
            $black.self::RS.'@Rozmiar', $size42.self::RS.'@Kolor',
        ], $tokens->pairAny);
    }

    public function testTheValuesOfOneOptionOfTwoVariantsAreNotMixedIntoOnePair(): void
    {
        // Black 42 and white 43: black is never paired with 43.
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(100, true, ['Kolor' => 'czarne', 'Rozmiar' => '42']),
            self::variant(100, true, ['Kolor' => 'białe', 'Rozmiar' => '43']),
        ]));

        self::assertNotContains('Kolor'.self::US.'czarne'.self::RS.'Rozmiar'.self::US.'43', $tokens->pairAny);
        self::assertContains('Kolor'.self::US.'czarne'.self::RS.'Rozmiar'.self::US.'42', $tokens->pairAny);
    }

    public function testAProductWithOneAxisHasNoPairs(): void
    {
        $tokens = ProductFacetTokens::of(self::document([self::variant(500, true, ['Rozmiar' => 'M']), self::variant(500, true, ['Rozmiar' => 'L'])]));

        self::assertSame([], $tokens->pairAny);
        self::assertSame([], $tokens->pairBuy);
        self::assertEqualsCanonicalizing(['#p', '#c', '@Rozmiar', 'Rozmiar'.self::US.'M', 'Rozmiar'.self::US.'L', '$'], $tokens->facets);
        self::assertSame([500], $tokens->buyPrices);
    }

    public function testAProductWithoutAxesHasNoAxisNoOptionAndNoPairTokens(): void
    {
        $tokens = ProductFacetTokens::of(self::document([self::variant(4_900, true, [])]));

        self::assertEqualsCanonicalizing(['#p', '#c', '$'], $tokens->facets);
        self::assertEqualsCanonicalizing(['#p', '#c'], $tokens->buyFacets);
        self::assertSame([], $tokens->pairAny);
        self::assertSame([], $tokens->pairBuy);
        self::assertSame([[]], $tokens->variantAxes);
        foreach ([$tokens->facets, $tokens->buyFacets] as $field) {
            self::assertSame([], array_filter($field, static fn (string $token): bool => str_starts_with($token, '@') || str_contains($token, self::US)));
        }
    }

    public function testAProductWithoutAPurchasableVariantHasNoBuyTokensNoDollarAndNoPrices(): void
    {
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(18_900, false, ['Kolor' => 'czarne']),
            self::variant(null, false, ['Kolor' => 'białe']),
        ], fromGross: null));

        self::assertEqualsCanonicalizing(['#p', '#c', '@Kolor', 'Kolor'.self::US.'czarne', 'Kolor'.self::US.'białe'], $tokens->facets);
        self::assertNotContains('$', $tokens->facets);
        self::assertSame([], $tokens->buyFacets);
        self::assertSame([], $tokens->pairBuy);
        self::assertFalse($tokens->purchasable);
        self::assertSame([], $tokens->buyPrices);
        self::assertNull($tokens->maxGross);
    }

    public function testADraftWithoutOfferFactsHasNoPrices(): void
    {
        // The offer of a draft is empty (no price, nothing to buy): its document has the variants and no prices.
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(null, false, ['Kolor' => 'czarne', 'Rozmiar' => '42']),
        ], fromGross: null, status: ProductStatus::Draft));

        self::assertFalse($tokens->purchasable);
        self::assertSame([], $tokens->buyPrices);
        self::assertNull($tokens->maxGross);
        self::assertSame([], $tokens->buyFacets);
        self::assertSame([], $tokens->pairBuy);
        self::assertNotContains('$', $tokens->facets);
        self::assertContains('@Kolor', $tokens->facets);
    }

    public function testThePricesAreTheDistinctOnesOfPurchasableVariantsAndMaxGrossIsTheHighest(): void
    {
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(3_000, true, ['Rozmiar' => 'S']),
            self::variant(1_000, true, ['Rozmiar' => 'M']),
            self::variant(3_000, true, ['Rozmiar' => 'L']),
            // Not purchasable, so its price is neither a price to filter by nor the highest.
            self::variant(9_000, false, ['Rozmiar' => 'XL']),
            // Purchasable without a price: nothing to add.
            self::variant(null, true, ['Rozmiar' => 'XXL']),
        ]));

        self::assertEqualsCanonicalizing([1_000, 3_000], $tokens->buyPrices);
        self::assertSame(3_000, $tokens->maxGross);
        self::assertTrue($tokens->purchasable);
    }

    public function testTokensKeepTheCaseAndThePolishLettersAndSeparateValuesOfDifferentAxes(): void
    {
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(100, true, ['Kolor' => 'Żółty', 'Rozmiar' => 'żółty']),
            self::variant(100, true, ['Kolor' => 'żółty', 'Rozmiar' => 'Żółty']),
            self::variant(100, true, ['Kolor' => 'S', 'Rozmiar' => 'S']),
        ]));

        foreach (['Kolor'.self::US.'Żółty', 'Kolor'.self::US.'żółty', 'Rozmiar'.self::US.'Żółty', 'Rozmiar'.self::US.'żółty', 'Kolor'.self::US.'S', 'Rozmiar'.self::US.'S'] as $token) {
            self::assertContains($token, $tokens->facets);
        }
        // The same value on two axes is two tokens.
        self::assertSame(1, \count(array_keys($tokens->facets, 'Kolor'.self::US.'S', true)));
        self::assertSame(1, \count(array_keys($tokens->facets, 'Rozmiar'.self::US.'S', true)));
    }

    public function testNoTokenRepeatsInAField(): void
    {
        $tokens = ProductFacetTokens::of(self::document([
            self::variant(100, true, ['Kolor' => 'czarne', 'Rozmiar' => '42']),
            self::variant(100, true, ['Kolor' => 'czarne', 'Rozmiar' => '43']),
            self::variant(100, true, ['Kolor' => 'białe', 'Rozmiar' => '42']),
        ], categoryAncestorIds: ['p', 'c', 'p']));

        foreach ([$tokens->facets, $tokens->buyFacets, $tokens->pairAny, $tokens->pairBuy, $tokens->buyPrices] as $field) {
            self::assertSame(array_values(array_unique($field)), $field);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namesWithSyntaxCharacters(): iterable
    {
        yield 'the characters of the prefixes' => ['#Kolor', '@Rozmiar$'];
        yield 'a dollar' => ['$', '$'];
        yield 'the characters of regular expressions' => ['Wymiar (cm)', '50.*'];
        yield 'a backslash and brackets' => ['Os\\[a-z]', '\\d+|x?'];
        yield 'look-alikes of the separators' => ["Kolor\u{241F}", "a\u{241E}b"];
    }

    #[DataProvider('namesWithSyntaxCharacters')]
    public function testSyntaxCharactersInAnAxisOrAValueAreKeptAsTheyAre(string $axis, string $value): void
    {
        $tokens = ProductFacetTokens::of(self::document([self::variant(100, true, [$axis => $value])]));

        self::assertContains('@'.$axis, $tokens->facets);
        self::assertContains($axis.self::US.$value, $tokens->facets);
        self::assertContains($axis.self::US.$value, $tokens->buyFacets);
    }

    /**
     * @return iterable<string, array{ProductDocument}>
     */
    public static function productsBreakingTheModel(): iterable
    {
        yield 'a variant with three options' => [self::document([self::variant(100, true, ['A' => 'x', 'B' => 'y', 'C' => 'z'])])];
        yield 'a repeated tuple of options' => [self::document([
            self::variant(100, true, ['Kolor' => 'czarne', 'Rozmiar' => '42']),
            self::variant(200, false, ['Kolor' => 'białe', 'Rozmiar' => '42']),
            self::variant(300, true, ['Kolor' => 'czarne', 'Rozmiar' => '42']),
        ])];
        yield 'a repeated tuple of one option' => [self::document([self::variant(100, true, ['Kolor' => 'czarne']), self::variant(null, false, ['Kolor' => 'czarne'])])];
        yield 'two default variants' => [self::document([self::variant(100, true, []), self::variant(200, true, [])])];
        yield 'the pair separator in an axis' => [self::document([self::variant(100, true, ["Ko\u{1E}lor" => 'czarne'])])];
        yield 'the pair separator in a value' => [self::document([self::variant(100, true, ['Kolor' => "cza\u{1E}rne"])])];
        yield 'the option separator in an axis' => [self::document([self::variant(100, true, ["Ko\u{1F}lor" => 'czarne'])])];
        yield 'the option separator in a value' => [self::document([self::variant(100, true, ['Kolor' => "cza\u{1F}rne"])])];
        yield 'the option separator in a value of the second axis' => [self::document([
            self::variant(100, true, ['Kolor' => 'czarne', 'Rozmiar' => "4\u{1F}2"]),
        ])];
        yield 'an option without the separator' => [self::document([new VariantDocument(100, true, ['Kolorczarne'])])];
    }

    #[DataProvider('productsBreakingTheModel')]
    public function testAProductBreakingARuleOfTheModelIsRefusedWithItsId(ProductDocument $document): void
    {
        try {
            ProductFacetTokens::of($document);
            self::fail('Expected SearchIndexError.');
        } catch (SearchIndexError $e) {
            self::assertStringContainsString(self::ID, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function charactersToEscape(): iterable
    {
        foreach (['#', '@', '$', '.', '*', '(', '\\', ')', '[', ']', '{', '}', '+', '?', '|', '^', '~', '&', '"', '<', '>', '-', ' ', ':', '/', "\u{1E}", "\u{1F}", "\u{241F}", "\u{0301}"] as $character) {
            yield \sprintf('U+%04X', mb_ord($character, 'UTF-8')) => [$character];
        }
    }

    #[DataProvider('charactersToEscape')]
    public function testEveryCharacterThatIsNoLetterAndNoDigitIsEscapedWithABackslash(string $character): void
    {
        self::assertSame('\\'.$character, ProductFacetTokens::escapeForRegexp($character));
    }

    public function testLettersAndDigitsAreNotEscapedPolishOnesIncluded(): void
    {
        self::assertSame('Kolor42ąćęłńóśźżĄĆĘŁŃÓŚŹŻ', ProductFacetTokens::escapeForRegexp('Kolor42ąćęłńóśźżĄĆĘŁŃÓŚŹŻ'));
        self::assertSame('', ProductFacetTokens::escapeForRegexp(''));
    }

    public function testTheSyntaxCharactersOfTheTokensAreEscapedOneByOne(): void
    {
        self::assertSame('\\#\\@\\$\\.\\*\\(\\\\', ProductFacetTokens::escapeForRegexp('#@$.*(\\'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textsForTheRegexpCheck(): iterable
    {
        yield 'a plain word' => ['Kolor'];
        yield 'brackets and a dot' => ['Wymiar (cm).*'];
        yield 'a backslash before a letter' => ['\\E x \\Q y'];
        yield 'an alternation' => ['a|b'];
        yield 'a class' => ['[a-z]+'];
        yield 'the characters of the prefixes' => ['#@$'];
        yield 'with the separators' => ["a\u{1E}b\u{1F}c"];
        yield 'Polish' => ['Żółty (ŻÓŁTY)'];
    }

    #[DataProvider('textsForTheRegexpCheck')]
    public function testAnEscapedTextMatchesItselfAndNothingElse(string $text): void
    {
        $pattern = '/\A'.ProductFacetTokens::escapeForRegexp($text).'\z/su';

        self::assertSame(1, preg_match($pattern, $text));
        self::assertSame(0, preg_match($pattern, $text.'x'));
        self::assertSame(0, preg_match($pattern, 'x'.$text));
        self::assertSame(0, preg_match($pattern, ''));
    }

    public function testTheOptionsOfAnAxisPatternMatchOnlyTheOptionsOfThatAxis(): void
    {
        $pattern = '/\A'.ProductFacetTokens::optionsOfAxisPattern('Kolor').'\z/su';

        self::assertSame(1, preg_match($pattern, 'Kolor'.self::US.'czarne'));
        self::assertSame(1, preg_match($pattern, 'Kolor'.self::US.'.*'));
        // An axis that has this one as its prefix, a dot standing for a letter, and tokens of other kinds.
        self::assertSame(0, preg_match($pattern, 'Kolor ramy'.self::US.'czarne'));
        self::assertSame(0, preg_match($pattern, 'Kolor2'.self::US.'czarne'));
        self::assertSame(0, preg_match($pattern, '@Kolor'));
        self::assertSame(0, preg_match($pattern, 'Kolor'));

        $dotted = '/\A'.ProductFacetTokens::optionsOfAxisPattern('Ko.or').'\z/su';
        self::assertSame(1, preg_match($dotted, 'Ko.or'.self::US.'x'));
        self::assertSame(0, preg_match($dotted, 'Kolor'.self::US.'x'));
    }

    public function testThePairPartnersPatternMatchesTheTokensPairedWithOneOptionOnly(): void
    {
        $pattern = '/\A'.ProductFacetTokens::pairPartnersPattern('Kolor', 'czarne').'\z/su';
        $black = 'Kolor'.self::US.'czarne';

        self::assertSame(1, preg_match($pattern, $black.self::RS.'Rozmiar'.self::US.'42'));
        self::assertSame(1, preg_match($pattern, $black.self::RS.'@Rozmiar'));
        self::assertSame(0, preg_match($pattern, 'Kolor'.self::US.'czarne2'.self::RS.'Rozmiar'.self::US.'42'));
        self::assertSame(0, preg_match($pattern, 'Rozmiar'.self::US.'42'.self::RS.$black));
        self::assertSame(0, preg_match($pattern, $black));
    }

    /**
     * @param list<VariantDocument> $variants
     * @param list<string>          $categoryAncestorIds
     */
    private static function document(array $variants, ?int $fromGross = 100, ProductStatus $status = ProductStatus::Published, array $categoryAncestorIds = ['p', 'c']): ProductDocument
    {
        return new ProductDocument(
            id: self::ID,
            version: 1_762_400_000_123_456,
            name: 'Kurtka puchowa',
            slug: 'kurtka-puchowa',
            shortDescription: 'Ciepła kurtka na zimę',
            mainCategoryId: 'c',
            mainCategoryPath: 'p/c',
            categoryIds: ['c'],
            categoryAncestorIds: $categoryAncestorIds,
            mainImage: null,
            categoryNames: ['Odzież', 'Kurtki'],
            variants: $variants,
            fromGross: $fromGross,
            status: $status,
        );
    }

    /**
     * @param array<string, string> $options axis => value
     */
    private static function variant(?int $gross, bool $purchasable, array $options): VariantDocument
    {
        $axes = array_map(strval(...), array_keys($options));

        return new VariantDocument(
            $gross,
            $purchasable,
            array_map(static fn (string $axis, string $value): string => $axis.VariantDocument::OPTION_SEPARATOR.$value, $axes, $options),
        );
    }
}
