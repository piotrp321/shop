<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\VatRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceBreakdownTest extends TestCase
{
    private const int MAX_GROSS = 99_999_999;

    /**
     * Net is rounded HALF_UP to a grosz. The demo rates never produce an exact half (their denominators are odd
     * after reduction), so the cases sit just below and just above it; 100% does produce exact halves.
     */
    #[DataProvider('roundingBoundaries')]
    public function testNetIsRoundedHalfUpAndVatIsTheRemainder(int $basisPoints, int $gross, int $expectedNet, int $expectedVat): void
    {
        $breakdown = PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints));

        self::assertSame($expectedNet, $breakdown->net()->amount());
        self::assertSame($expectedVat, $breakdown->vat()->amount());
        self::assertSame($gross, $breakdown->gross()->amount());
        self::assertSame($basisPoints, $breakdown->rate()->basisPoints());
    }

    /** @return iterable<string, array{int, int, int, int}> rate bp, gross, net, VAT */
    public static function roundingBoundaries(): iterable
    {
        yield '23%: 0,08 → 6,504 rounds up' => [2300, 8, 7, 1];
        yield '23%: 1,15 → 93,496 rounds down' => [2300, 115, 93, 22];
        yield '23%: 123,00 is exactly 100,00 net' => [2300, 12300, 10000, 2300];
        yield '23%: 0,01 → 0,813 rounds up, no VAT' => [2300, 1, 1, 0];
        yield '5%: 0,10 → 9,524 rounds up, no VAT' => [500, 10, 10, 0];
        yield '5%: 0,11 → 10,476 rounds down' => [500, 11, 10, 1];
        yield '5%: 105,00 is exactly 100,00 net' => [500, 10500, 10000, 500];
        yield '8%: 0,07 → 6,481 rounds down' => [800, 7, 6, 1];
        yield '8%: 0,20 → 18,519 rounds up' => [800, 20, 19, 1];
        yield '8%: 108,00 is exactly 100,00 net' => [800, 10800, 10000, 800];
        yield '100%: 0,01 → exactly 0,5 rounds up' => [10000, 1, 1, 0];
        yield '100%: 0,05 → exactly 2,5 rounds up, not to even' => [10000, 5, 3, 2];
        yield '0%: net is the gross' => [0, 5490, 5490, 0];
        yield '23%: maximum' => [2300, self::MAX_GROSS, 81_300_812, 18_699_187];
        yield '8%: maximum' => [800, self::MAX_GROSS, 92_592_592, 7_407_407];
        yield '5%: maximum' => [500, self::MAX_GROSS, 95_238_094, 4_761_905];
        yield '0%: maximum' => [0, self::MAX_GROSS, self::MAX_GROSS, 0];
    }

    /**
     * Property: for every gross from 1 grosz to 1000 zł and the maximum, at every rate in use and at 0%, net + VAT is
     * the gross, VAT is not negative and net equals a reference computed another way (quotient and remainder).
     */
    #[DataProvider('ratesInUse')]
    public function testBreakdownMatchesTheReferenceForEveryGrossUpToOneThousandZlotyAndTheMaximum(int $basisPoints): void
    {
        $rate = VatRate::fromBasisPoints($basisPoints);
        $mismatches = [];

        foreach ([...range(1, 100_000), self::MAX_GROSS] as $gross) {
            $breakdown = PriceBreakdown::fromGross(GrossPrice::pln($gross), $rate);
            $net = $breakdown->net()->amount();
            $vat = $breakdown->vat()->amount();
            $reference = self::referenceNet($gross, $basisPoints);

            if ($net + $vat !== $gross || $vat < 0 || $net !== $reference) {
                $mismatches[] = \sprintf('gross %d: net %d, VAT %d, reference net %d', $gross, $net, $vat, $reference);
                if (\count($mismatches) >= 10) {
                    break;
                }
            }
        }

        self::assertSame([], $mismatches);
    }

    /** @return iterable<string, array{int}> */
    public static function ratesInUse(): iterable
    {
        yield '0 bp' => [0];
        foreach (array_unique(array_map(static fn (VatRate $rate): int => $rate->basisPoints(), new PolishVatRates()->all())) as $basisPoints) {
            yield $basisPoints.' bp' => [$basisPoints];
        }
    }

    /**
     * Net = gross / (1 + rate), rounded half up: the quotient, plus one when the remainder is at least half the divisor.
     */
    private static function referenceNet(int $gross, int $basisPoints): int
    {
        $dividend = $gross * 10000;
        $divisor = 10000 + $basisPoints;
        $quotient = intdiv($dividend, $divisor);
        $remainder = $dividend % $divisor;

        return $remainder * 2 >= $divisor ? $quotient + 1 : $quotient;
    }

    public function testRestoreKeepsAStoredBreakdown(): void
    {
        $stored = PriceBreakdown::restore(GrossPrice::pln(5490), 5229, 261, VatRate::fromBasisPoints(500));

        self::assertTrue($stored->equals(PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints(500))));
    }

    #[DataProvider('inconsistentStoredBreakdowns')]
    public function testRestoreRejectsAnInconsistentBreakdown(int $net, int $vat): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PriceBreakdown::restore(GrossPrice::pln(5490), $net, $vat, VatRate::fromBasisPoints(500));
    }

    /** @return iterable<string, array{int, int}> */
    public static function inconsistentStoredBreakdowns(): iterable
    {
        yield 'sum below the gross' => [5229, 260];
        yield 'sum above the gross' => [5229, 262];
        yield 'negative VAT' => [5491, -1];
        yield 'negative net' => [-1, 5491];
    }

    public function testBreakdownsDifferWhenAnyPartDiffers(): void
    {
        $base = PriceBreakdown::fromGross(GrossPrice::pln(10000), VatRate::fromBasisPoints(2300));

        self::assertTrue($base->equals(PriceBreakdown::fromGross(GrossPrice::pln(10000), VatRate::fromBasisPoints(2300))));
        self::assertFalse($base->equals(PriceBreakdown::fromGross(GrossPrice::pln(10001), VatRate::fromBasisPoints(2300))));
        self::assertFalse($base->equals(PriceBreakdown::fromGross(GrossPrice::pln(10000), VatRate::fromBasisPoints(800))));
        self::assertFalse($base->equals(PriceBreakdown::restore(GrossPrice::pln(10000), 8131, 1869, VatRate::fromBasisPoints(2300))));
    }

    public function testALineGrossOfZeroGroszeHasNeitherNetNorVat(): void
    {
        $line = PriceBreakdown::ofLineGross(Money::of(0, Currency::PLN), VatRate::fromBasisPoints(2300));

        self::assertTrue($line->gross()->equals(Money::of(0, Currency::PLN)));
        self::assertTrue($line->net()->equals(Money::of(0, Currency::PLN)));
        self::assertTrue($line->vat()->equals(Money::of(0, Currency::PLN)));
        self::assertSame(2300, $line->rate()->basisPoints());
    }

    /**
     * The largest line: 99 units at the largest gross price, beyond the range of GrossPrice and of 32 bits.
     */
    #[DataProvider('ratesInUse')]
    public function testTheMaximumLineGrossIsSplitExactlyWithNetRoundedHalfUp(int $basisPoints): void
    {
        $gross = 99 * self::MAX_GROSS;

        $line = PriceBreakdown::ofLineGross(Money::of($gross, Currency::PLN), VatRate::fromBasisPoints($basisPoints));

        self::assertSame(PriceBreakdown::MAX_LINE_GROSS, $gross);
        self::assertSame($gross, $line->gross()->amount());
        self::assertSame(self::referenceNet($gross, $basisPoints), $line->net()->amount());
        self::assertSame($gross, $line->net()->amount() + $line->vat()->amount());
        self::assertGreaterThanOrEqual(0, $line->vat()->amount());
    }

    /**
     * One formula: within the range of GrossPrice a line gross gives exactly the net and VAT of fromGross.
     */
    #[DataProvider('ratesInUse')]
    public function testALineGrossGivesTheNetAndVatOfFromGrossWithinTheGrossPriceRange(int $basisPoints): void
    {
        $rate = VatRate::fromBasisPoints($basisPoints);
        $mismatches = [];

        foreach ([...range(1, 100_000), self::MAX_GROSS] as $gross) {
            $price = PriceBreakdown::fromGross(GrossPrice::pln($gross), $rate);
            $line = PriceBreakdown::ofLineGross(Money::of($gross, Currency::PLN), $rate);
            if (!$line->net()->equals($price->net()) || !$line->vat()->equals($price->vat())) {
                $mismatches[] = \sprintf('gross %d: line %d + %d, price %d + %d', $gross, $line->net()->amount(), $line->vat()->amount(), $price->net()->amount(), $price->vat()->amount());
                if (\count($mismatches) >= 10) {
                    break;
                }
            }
        }

        self::assertSame([], $mismatches);
    }

    /** @return iterable<string, array{int}> */
    public static function linesOutOfRange(): iterable
    {
        yield 'negative' => [-1];
        yield 'above 99 units at the maximum price' => [99 * self::MAX_GROSS + 1];
    }

    #[DataProvider('linesOutOfRange')]
    public function testALineGrossOutOfItsRangeIsRefused(int $gross): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PriceBreakdown::ofLineGross(Money::of($gross, Currency::PLN), VatRate::fromBasisPoints(2300));
    }
}
