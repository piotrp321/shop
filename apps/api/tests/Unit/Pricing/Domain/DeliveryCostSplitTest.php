<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Basket\BasketQuote;
use App\Pricing\Domain\Basket\DeliveryCostSplit;
use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\LineBreakdown;
use App\Pricing\Domain\Price\PriceBreakdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The split of the delivery cost over the VAT rates of the goods (docs/features/delivery/spec.md, W3 and P4).
 */
final class DeliveryCostSplitTest extends TestCase
{
    public function testTheExampleOfTheSpecificationGivesTheGroszLeftToTheHigherRate(): void
    {
        $shares = DeliveryCostSplit::of(1999, [2300 => 10_000, 800 => 5_000]);

        self::assertSame([[2300, 1333, 1084, 249], [800, 666, 617, 49]], self::rows($shares));
    }

    public function testTheRatesAreInDescendingOrderWhateverTheOrderOfTheMap(): void
    {
        $shares = DeliveryCostSplit::of(1999, [800 => 5_000, 0 => 2_000, 2300 => 10_000, 500 => 3_000]);

        self::assertSame([2300, 800, 500, 0], array_map(static fn (LineBreakdown $share): int => $share->rate()->basisPoints(), $shares));
        self::assertSame(1999, array_sum(array_map(static fn (LineBreakdown $share): int => $share->gross()->amount(), $shares)));
    }

    public function testOneRateTakesTheWholeCost(): void
    {
        self::assertSame([[2300, 1999, 1625, 374]], self::rows(DeliveryCostSplit::of(1999, [2300 => 12_345])));
    }

    public function testAGroupWorthNothingGetsNoShare(): void
    {
        self::assertSame([[2300, 1999, 1625, 374]], self::rows(DeliveryCostSplit::of(1999, [2300 => 10_000, 800 => 0])));
    }

    public function testAFreeDeliveryHasNoShares(): void
    {
        self::assertSame([], DeliveryCostSplit::of(0, [2300 => 10_000, 800 => 5_000]));
    }

    public function testACostAboveTheGoodsOfOneRateIsSplitTheSameWay(): void
    {
        // A product of 1 zł and a delivery of 19,99 zł.
        self::assertSame([[2300, 1999, 1625, 374]], self::rows(DeliveryCostSplit::of(1999, [2300 => 100])));
    }

    public function testACostAboveTheGoodsOfSeveralRatesGivesTheGroszLeftToTheLargestRemainder(): void
    {
        // 60 × 1999 / 100 = 1199 rest 40, 40 × 1999 / 100 = 799 rest 60: the grosz left goes to 8%.
        self::assertSame([[2300, 1199, 975, 224], [800, 800, 741, 59]], self::rows(DeliveryCostSplit::of(1999, [2300 => 60, 800 => 40])));
    }

    public function testATieOfRemaindersGivesTheGroszToTheHigherRate(): void
    {
        self::assertSame([[2300, 1, 1, 0]], self::rows(DeliveryCostSplit::of(1, [800 => 5_000, 2300 => 5_000])));
        self::assertSame([[2300, 1, 1, 0], [800, 1, 1, 0]], self::rows(DeliveryCostSplit::of(2, [500 => 1, 800 => 1, 2300 => 1])));
    }

    public function testTheLargestCostOverTheLargestGoodsIsComputedExactly(): void
    {
        $max = PriceBreakdown::MAX_LINE_GROSS;
        $shares = DeliveryCostSplit::of($max, [2300 => 50 * $max - 1, 800 => 1]);

        self::assertSame([2300], array_map(static fn (LineBreakdown $share): int => $share->rate()->basisPoints(), $shares));
        self::assertSame($max, $shares[0]->gross()->amount());
    }

    /**
     * On random goods and costs every share is above zero, its net and VAT are those of its gross at its rate and add
     * up to it, the rates are those of goods worth something, distinct and descending, and the shares add up to the cost.
     */
    public function testOnRandomDataTheSharesAddUpToTheCostAndEachShareAddsUp(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(20261120));
        $rates = [2300, 800, 500, 0];
        for ($round = 0; $round < 2000; ++$round) {
            $goods = [];
            foreach ($rates as $rate) {
                if (0 === $random->getInt(0, 2)) {
                    $goods[$rate] = 0 === $random->getInt(0, 3) ? 0 : $random->getInt(1, 0 === $random->getInt(0, 1) ? 500 : 50 * PriceBreakdown::MAX_LINE_GROSS);
                }
            }
            $goods[$rates[$random->getInt(0, 3)]] = $random->getInt(1, 1_000_000);
            $cost = $random->getInt(0, 0 === $random->getInt(0, 1) ? 3_000 : 99_999_999);

            $shares = DeliveryCostSplit::of($cost, $goods);

            $total = 0;
            $previous = \PHP_INT_MAX;
            foreach ($shares as $share) {
                $gross = $share->gross()->amount();
                $rate = $share->rate()->basisPoints();
                self::assertGreaterThan(0, $gross);
                self::assertSame($gross, $share->net()->amount() + $share->vat()->amount());
                self::assertTrue(PriceBreakdown::ofLineGross(Money::of($gross, Currency::PLN), $share->rate())->net()->equals($share->net()));
                self::assertLessThan($previous, $rate);
                self::assertGreaterThan(0, $goods[$rate] ?? 0);
                $previous = $rate;
                $total += $gross;
            }
            self::assertSame($cost, $total);
        }
    }

    /**
     * @param array<int, int> $goods
     */
    #[DataProvider('invalidSplits')]
    public function testAnInvalidSplitIsRefused(int $cost, array $goods): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeliveryCostSplit::of($cost, $goods);
    }

    /** @return iterable<string, array{int, array<int, int>}> */
    public static function invalidSplits(): iterable
    {
        yield 'no rates' => [1999, []];
        yield 'no rates and a free delivery' => [0, []];
        yield 'goods worth nothing' => [1999, [2300 => 0, 800 => 0]];
        yield 'goods worth nothing and a free delivery' => [0, [2300 => 0]];
        yield 'a negative cost' => [-1, [2300 => 10_000]];
        yield 'a cost above the largest line' => [PriceBreakdown::MAX_LINE_GROSS + 1, [2300 => 10_000]];
        yield 'negative goods' => [1999, [2300 => 10_000, 800 => -1]];
        yield 'a negative rate' => [1999, [-1 => 10_000]];
        yield 'a rate above 100%' => [1999, [10_001 => 10_000]];
    }

    /**
     * BasketQuote::allocate is correct for any amount at a positive sum, also one at or above the sum (W3).
     */
    public function testAllocateSplitsAnAmountAtOrAboveTheSum(): void
    {
        self::assertSame([60, 40], BasketQuote::allocate([60, 40], 100, 100));
        self::assertSame([1199, 800], BasketQuote::allocate([60, 40], 100, 1999));
        self::assertSame([1999], BasketQuote::allocate([1], 1, 1999));
        self::assertSame([2, 1, 0], BasketQuote::allocate([1, 1, 0], 2, 3));
    }

    /**
     * @param list<LineBreakdown> $shares
     *
     * @return list<array{int, int, int, int}> rate bp, gross, net, VAT
     */
    private static function rows(array $shares): array
    {
        return array_map(static fn (LineBreakdown $share): array => [
            $share->rate()->basisPoints(),
            $share->gross()->amount(),
            $share->net()->amount(),
            $share->vat()->amount(),
        ], $shares);
    }
}
