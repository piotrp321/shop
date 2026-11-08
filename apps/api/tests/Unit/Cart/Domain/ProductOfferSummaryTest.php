<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductOfferSummary;
use App\Cart\Domain\StockLevel;
use App\Cart\Domain\VariantOffer;
use App\Cart\Domain\VariantPromotion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The summary of a product of the list: the "from" price and the level of the product come only from the variants
 * that can be bought (a current price and an available unit, ProductOffer::purchasability(1)).
 */
final class ProductOfferSummaryTest extends TestCase
{
    public function testAVariantWithoutACurrentPriceIsLeftOut(): void
    {
        $summary = ProductOfferSummary::of([
            self::variant(null, 50, StockLevel::InStock),
            self::variant(9900, 3, StockLevel::LowStock),
        ]);

        self::assertSame(9900, $summary->fromGross?->amount());
        self::assertFalse($summary->hasPriceRange);
        self::assertSame(StockLevel::LowStock, $summary->level);
    }

    /**
     * Pricing gives no current price for a price at a VAT rate that is no longer the rate of its class, so such a
     * variant reaches the summary without a gross amount, cheaper than the others or not.
     */
    public function testAVariantWithAnOutdatedPriceIsLeftOutLikeOneWithoutAPrice(): void
    {
        $summary = ProductOfferSummary::of([
            self::variant(15000, 10, StockLevel::InStock),
            self::variant(null, 10, StockLevel::InStock),
        ]);

        self::assertSame(15000, $summary->fromGross?->amount());
        self::assertFalse($summary->hasPriceRange);
    }

    public function testAVariantWithNoAvailableUnitIsLeftOut(): void
    {
        $summary = ProductOfferSummary::of([
            self::variant(5000, 0, StockLevel::OutOfStock),
            self::variant(7000, 2, StockLevel::LowStock),
        ]);

        self::assertSame(7000, $summary->fromGross?->amount());
        self::assertFalse($summary->hasPriceRange);
        self::assertSame(StockLevel::LowStock, $summary->level);
    }

    public function testEqualPricesHaveNoRange(): void
    {
        $summary = ProductOfferSummary::of([
            self::variant(12900, 10, StockLevel::InStock),
            self::variant(12900, 4, StockLevel::LowStock),
            self::variant(12900, 30, StockLevel::InStock),
        ]);

        self::assertSame(12900, $summary->fromGross?->amount());
        self::assertFalse($summary->hasPriceRange);
    }

    public function testDifferentPricesGiveTheLowestWithARange(): void
    {
        $summary = ProductOfferSummary::of([
            self::variant(4900, 10, StockLevel::InStock),
            self::variant(3900, 10, StockLevel::InStock),
            self::variant(5900, 10, StockLevel::InStock),
        ]);

        self::assertSame(3900, $summary->fromGross?->amount());
        self::assertTrue($summary->hasPriceRange);
    }

    /**
     * A cheaper variant that cannot be bought neither lowers the price nor makes a range.
     */
    public function testTheRangeCountsOnlyThePurchasableVariants(): void
    {
        $summary = ProductOfferSummary::of([
            self::variant(1000, 0, StockLevel::OutOfStock),
            self::variant(8000, 10, StockLevel::InStock),
            self::variant(8000, 1, StockLevel::LowStock),
        ]);

        self::assertSame(8000, $summary->fromGross?->amount());
        self::assertFalse($summary->hasPriceRange);
    }

    /**
     * @return iterable<string, array{list<VariantOffer>}>
     */
    public static function nothingToBuy(): iterable
    {
        yield 'no variant (a product unknown to Catalog)' => [[]];
        yield 'one variant without a price' => [[self::variant(null, 10, StockLevel::InStock)]];
        yield 'one variant without units' => [[self::variant(5000, 0, StockLevel::OutOfStock)]];
        yield 'variants without a price or without units' => [[
            self::variant(null, 20, StockLevel::InStock),
            self::variant(5000, 0, StockLevel::OutOfStock),
            self::variant(null, 0, StockLevel::OutOfStock),
        ]];
    }

    /**
     * @param list<VariantOffer> $variants
     */
    #[DataProvider('nothingToBuy')]
    public function testWithoutAPurchasableVariantThereIsNoPriceAndTheProductIsOutOfStock(array $variants): void
    {
        $summary = ProductOfferSummary::of($variants);

        self::assertNull($summary->fromGross);
        self::assertFalse($summary->hasPriceRange);
        self::assertSame(StockLevel::OutOfStock, $summary->level);
    }

    /**
     * @return iterable<string, array{list<VariantOffer>, StockLevel}>
     */
    public static function levels(): iterable
    {
        yield 'only low stock' => [[self::variant(1000, 2, StockLevel::LowStock), self::variant(1000, 5, StockLevel::LowStock)], StockLevel::LowStock];
        yield 'in stock after low stock' => [[self::variant(1000, 2, StockLevel::LowStock), self::variant(1000, 20, StockLevel::InStock)], StockLevel::InStock];
        yield 'in stock before low stock' => [[self::variant(1000, 20, StockLevel::InStock), self::variant(1000, 2, StockLevel::LowStock)], StockLevel::InStock];
        yield 'in stock only without a price' => [[self::variant(null, 20, StockLevel::InStock), self::variant(1000, 2, StockLevel::LowStock)], StockLevel::LowStock];
    }

    /**
     * @param list<VariantOffer> $variants
     */
    #[DataProvider('levels')]
    public function testTheLevelIsTheBestOfThePurchasableVariants(array $variants, StockLevel $expected): void
    {
        self::assertSame($expected, ProductOfferSummary::of($variants)->level);
    }

    public function testAVariantIsPurchasableWithACurrentPriceAndAnAvailableUnit(): void
    {
        self::assertTrue(self::variant(1000, 1, StockLevel::LowStock)->isPurchasable());
        self::assertFalse(self::variant(null, 1, StockLevel::LowStock)->isPurchasable());
        self::assertFalse(self::variant(1000, 0, StockLevel::OutOfStock)->isPurchasable());
    }

    public function testTheFromPromotionIsThePromotionOfTheVariantThatGivesTheFromPrice(): void
    {
        $promotion = self::promotion(8_000);

        $summary = ProductOfferSummary::of([
            self::variant(12_000, 5, StockLevel::InStock, self::promotion(10_000)),
            self::variant(9_000, 5, StockLevel::InStock, $promotion),
            self::variant(9_500, 5, StockLevel::InStock),
        ]);

        self::assertSame(9_000, $summary->fromGross?->amount());
        self::assertSame($promotion, $summary->fromPromotion);
    }

    public function testWhenTheCheapestPurchasableVariantIsNotPromotedThereIsNoFromPromotion(): void
    {
        $summary = ProductOfferSummary::of([
            // Cheaper and promoted, but out of stock.
            self::variant(5_000, 0, StockLevel::OutOfStock, self::promotion(6_000)),
            self::variant(9_000, 5, StockLevel::InStock),
            self::variant(9_500, 5, StockLevel::InStock, self::promotion(11_000)),
        ]);

        self::assertSame(9_000, $summary->fromGross?->amount());
        self::assertNull($summary->fromPromotion);
    }

    public function testOfVariantsOfTheSameFromPriceTheFirstInOrderGivesTheFromPromotion(): void
    {
        $promotion = self::promotion(null);

        self::assertSame($promotion, ProductOfferSummary::of([
            self::variant(9_000, 5, StockLevel::InStock, $promotion),
            self::variant(9_000, 5, StockLevel::InStock),
        ])->fromPromotion);
        self::assertNull(ProductOfferSummary::of([
            self::variant(9_000, 5, StockLevel::InStock),
            self::variant(9_000, 5, StockLevel::InStock, $promotion),
        ])->fromPromotion);
    }

    public function testWithoutAPurchasableVariantThereIsNoFromPromotion(): void
    {
        self::assertNull(ProductOfferSummary::of([self::variant(9_000, 0, StockLevel::OutOfStock, self::promotion(9_500))])->fromPromotion);
        self::assertNull(ProductOfferSummary::of([])->fromPromotion);
    }

    private static function variant(?int $gross, int $available, StockLevel $level, ?VariantPromotion $promotion = null): VariantOffer
    {
        return new VariantOffer(null === $gross ? null : Money::of($gross, Currency::PLN), $available, $level, $promotion);
    }

    private static function promotion(?int $lowestPriorGross): VariantPromotion
    {
        return new VariantPromotion(new \DateTimeImmutable('2026-11-20T00:00:00Z'), null === $lowestPriorGross ? null : Money::of($lowestPriorGross, Currency::PLN));
    }
}
