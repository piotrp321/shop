<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Basket\BasketLine;
use App\Pricing\Domain\Basket\BasketPrice;
use App\Pricing\Domain\Basket\BasketPromotion;
use App\Pricing\Domain\Basket\BasketQuote;
use App\Pricing\Domain\Basket\QuotedLine;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeRejection;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\EffectivePrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The expected amounts of the allocation were computed apart, with exact integers in Python (largest remainders,
 * ties to the earlier line).
 */
final class BasketQuoteTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-00000000000c';
    private const string CODE_ID = '0192f0c4-7c1a-7c4e-9a6e-0000000000dc';
    private const string PROMOTION = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';
    private const string NOW = '2026-11-10T12:00:00Z';

    public function testWithoutACodeTheLineNetComesFromTheLineGrossNotFromTheUnitNetTimesTheQuantity(): void
    {
        // 0,08 zł at 23% is 7 net + 1 VAT; three of them are 0,24 zł: 20 net + 4 VAT (unit net × 3 would be 21).
        $quote = BasketQuote::of([self::line(self::A, 3)], [self::A => self::price(8)], null, 0, self::now());

        $line = $quote->lines()[0];
        self::assertSame(7, $line->unit()->net()->amount());
        self::assertSame(24, $line->grossBeforeDiscount()->amount());
        self::assertSame(0, $line->discount()->amount());
        self::assertSame([24, 20, 4], self::amounts($line));
        self::assertSame([24, 0, 20, 4, 24], self::totals($quote));
        self::assertNull($quote->discount());
        self::assertNull($quote->rejection());
    }

    public function testTheTotalsAreTheSumsOfTheLinesAndALineWithoutAPriceIsLeftOut(): void
    {
        $quote = BasketQuote::of(
            [self::line(self::A, 2), self::line(self::B, 1), self::line(self::C, 1)],
            [self::A => self::price(12345), self::C => self::price(5490, 'printed_book')],
            null,
            0,
            self::now(),
        );

        self::assertSame([self::A, self::C], array_map(static fn (QuotedLine $line): string => $line->variantId()->toString(), $quote->lines()));
        self::assertSame(2, $quote->lines()[0]->quantity());
        self::assertSame('standard', $quote->lines()[0]->taxClass()->toString());
        self::assertSame(2300, $quote->lines()[0]->amounts()->rate()->basisPoints());
        // 24 690 at 23%: 20 073 + 4 617; 5 490 at 5%: 5 229 + 261.
        self::assertSame([24690, 20073, 4617], self::amounts($quote->lines()[0]));
        self::assertSame([5490, 5229, 261], self::amounts($quote->lines()[1]));
        self::assertSame([30180, 0, 25302, 4878, 30180], self::totals($quote));
    }

    public function testAPercentCodeTakesTheRoundedDownPercentOfTheSubtotalAndGivesTheMissingGroszToTheLargestRemainder(): void
    {
        $code = self::percentCode(10);

        $quote = BasketQuote::of(
            [self::line(self::A, 1), self::line(self::B, 1), self::line(self::C, 1)],
            [self::A => self::price(333), self::B => self::price(333), self::C => self::price(334)],
            $code,
            0,
            self::now(),
        );

        // S = 1000, D = 100; 33 r 300, 33 r 300, 33 r 400: the missing grosz goes to C.
        self::assertSame([33, 33, 34], self::discounts($quote));
        self::assertSame([1000, 100, 900], [$quote->subtotalGross()->amount(), $quote->discountGross()->amount(), $quote->gross()->amount()]);
        $discount = $quote->discount();
        self::assertNotNull($discount);
        self::assertTrue($discount->codeId()->equals($code->id()));
        self::assertSame('WITAJ10', $discount->code()->toString());
        self::assertSame(DiscountKind::Percent, $discount->value()->kind());
        self::assertSame(10, $discount->value()->value());
        self::assertSame(100, $discount->amount()->amount());
        self::assertNull($quote->rejection());
    }

    public function testATieOfRemaindersGivesTheGroszToTheEarlierLine(): void
    {
        $quote = BasketQuote::of(
            [self::line(self::A, 1), self::line(self::B, 1), self::line(self::C, 1)],
            [self::A => self::price(100), self::B => self::price(100), self::C => self::price(100)],
            self::amountCode(100, 101),
            0,
            self::now(),
        );

        // S = 300, D = 100; each 33 r 100.
        self::assertSame([34, 33, 33], self::discounts($quote));
        self::assertSame([300, 100, 200], [$quote->subtotalGross()->amount(), $quote->discountGross()->amount(), $quote->gross()->amount()]);
    }

    public function testAnAmountCodeIsSpreadOverLinesOfDifferentRatesAndEachLineHasItsNetAndVatAfterTheDiscount(): void
    {
        $quote = BasketQuote::of(
            [self::line(self::A, 2), self::line(self::B, 1)],
            [self::A => self::price(12345), self::B => self::price(9999, 'printed_book')],
            self::amountCode(5000, 30000),
            0,
            self::now(),
        );

        // S = 34 689, D = 5000; A 3558 r 26 538, B 1441 r 8151.
        self::assertSame([3559, 1441], self::discounts($quote));
        self::assertSame([21131, 17180, 3951], self::amounts($quote->lines()[0]));
        self::assertSame([8558, 8150, 408], self::amounts($quote->lines()[1]));
        self::assertSame(24690, $quote->lines()[0]->grossBeforeDiscount()->amount());
        self::assertSame([34689, 5000, 25330, 4359, 29689], self::totals($quote));
        self::assertSame(DiscountKind::Amount, $quote->discount()?->value()->kind());
    }

    public function testALineOfOneGroszKeepsItsGroszWhenItsRemainderIsSmaller(): void
    {
        $quote = BasketQuote::of(
            [self::line(self::A, 1), self::line(self::B, 1)],
            [self::A => self::price(1), self::B => self::price(1000)],
            self::percentCode(50),
            0,
            self::now(),
        );

        // S = 1001, D = 500; A 0 r 500, B 499 r 501.
        self::assertSame([0, 500], self::discounts($quote));
        self::assertSame([1, 1, 0], self::amounts($quote->lines()[0]));
    }

    public function testALineOfOneGroszMayBeDiscountedToNothing(): void
    {
        $quote = BasketQuote::of(
            [self::line(self::A, 1), self::line(self::B, 1)],
            [self::A => self::price(1), self::B => self::price(1)],
            self::percentCode(50),
            0,
            self::now(),
        );

        // S = 2 is the implicit minimum of 50%, D = 1; a tie of remainders 1 and 1.
        self::assertSame([1, 0], self::discounts($quote));
        self::assertSame([0, 0, 0], self::amounts($quote->lines()[0]));
        self::assertSame([2, 1, 1, 0, 1], self::totals($quote));
    }

    public function testTheMaximumBasketIsComputedExactly(): void
    {
        $lines = [];
        $prices = [];
        for ($i = 0; $i < 50; ++$i) {
            $id = \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i);
            $lines[] = self::line($id, 99);
            $prices[$id] = self::price(GrossPrice::MAX);
        }

        $quote = BasketQuote::of($lines, $prices, self::percentCode(90), 0, self::now());

        // S = 50 × 99 × 99 999 999, D = floor(S × 90 / 100); g × D (about 4,4·10^21) is beyond 64 bits. All remainders
        // are equal, so the 45 missing grosze go to the first 45 lines.
        self::assertSame(494_999_995_050, $quote->subtotalGross()->amount());
        self::assertSame(445_499_995_545, $quote->discountGross()->amount());
        self::assertSame(49_499_999_505, $quote->gross()->amount());
        self::assertSame([...array_fill(0, 45, 8_909_999_911), ...array_fill(0, 5, 8_909_999_910)], self::discounts($quote));
        self::assertExact($quote);
    }

    public function testTheMaximumBasketOfDifferentPricesIsComputedExactly(): void
    {
        $lines = [];
        $prices = [];
        for ($i = 0; $i < 50; ++$i) {
            $id = \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i);
            $lines[] = self::line($id, 99);
            $prices[$id] = self::price(GrossPrice::MAX - $i * 1_234_567, 0 === $i % 2 ? 'standard' : 'energy_gel');
        }

        $quote = BasketQuote::of($lines, $prices, self::percentCode(90), 0, self::now());

        self::assertSame(345_277_882_125, $quote->subtotalGross()->amount());
        self::assertSame(310_750_093_912, $quote->discountGross()->amount());
        $discounts = self::discounts($quote);
        self::assertSame([8_909_999_911, 8_799_999_991, 3_520_003_846], [$discounts[0], $discounts[1], $discounts[49]]);
        self::assertExact($quote);
    }

    public function testAPromotedLineKeepsTheCopyOfItsPromotion(): void
    {
        $quote = BasketQuote::of(
            [self::line(self::A, 2)],
            [self::A => self::price(5490, 'printed_book', 20)],
            null,
            0,
            self::now(),
        );

        $line = $quote->lines()[0];
        // 5490 − floor(5490 × 20 / 100) = 4392 at 5%: 4183 + 209.
        self::assertSame([4183, 209, 4392], [$line->unit()->net()->amount(), $line->unit()->vat()->amount(), $line->unit()->gross()->amount()]);
        self::assertSame(5490, $line->regularUnitGross()->amount());
        $promotion = $line->promotion();
        self::assertNotNull($promotion);
        self::assertSame(self::PROMOTION, $promotion->id()->toString());
        self::assertSame('Jesień', $promotion->name());
        self::assertSame(20, $promotion->percentOff()->value());
        self::assertSame(8784, $line->grossBeforeDiscount()->amount());
    }

    public function testALineOutsideAPromotionHasNoPromotionAndItsCatalogGross(): void
    {
        $line = BasketQuote::of([self::line(self::A, 1)], [self::A => self::price(5490)], null, 0, self::now())->lines()[0];

        self::assertNull($line->promotion());
        self::assertSame(5490, $line->regularUnitGross()->amount());
    }

    /**
     * @param \Closure(): DiscountCode $code
     */
    #[DataProvider('rejectedCodes')]
    public function testARejectedCodeGivesTheQuoteWithoutDiscountAndTheRejection(\Closure $code, int $activeUses, DiscountCodeRejection $expected): void
    {
        $quote = BasketQuote::of(
            [self::line(self::A, 2), self::line(self::B, 1)],
            [self::A => self::price(4000), self::B => self::price(1001)],
            $code(),
            $activeUses,
            self::now(),
        );

        self::assertEquals($expected, $quote->rejection());
        self::assertNull($quote->discount());
        self::assertSame([0, 0], self::discounts($quote));
        self::assertSame([9001, 0, 7318, 1683, 9001], self::totals($quote));
    }

    /** @return iterable<string, array{\Closure(): DiscountCode, int, DiscountCodeRejection}> */
    public static function rejectedCodes(): iterable
    {
        yield 'disabled' => [static fn (): DiscountCode => self::percentCode(10, enabled: false), 0, DiscountCodeRejection::invalid()];
        yield 'not started' => [static fn (): DiscountCode => self::percentCode(10, startsAt: '2026-11-11T00:00:00Z'), 0, DiscountCodeRejection::invalid()];
        yield 'expired' => [static fn (): DiscountCode => self::percentCode(10, endsAt: self::NOW), 0, DiscountCodeRejection::invalid()];
        yield 'used up' => [static fn (): DiscountCode => self::percentCode(10, limit: 3), 3, DiscountCodeRejection::usedUp()];
        yield 'below the minimum of the code' => [static fn (): DiscountCode => self::amountCode(5000, 9002), 0, DiscountCodeRejection::belowMinimum(9002)];
    }

    public function testTheSubtotalAtTheMinimumGetsTheCode(): void
    {
        $quote = BasketQuote::of([self::line(self::A, 1)], [self::A => self::price(9002)], self::amountCode(5000, 9002), 0, self::now());

        self::assertSame(5000, $quote->discountGross()->amount());
        self::assertNull($quote->rejection());
    }

    public function testAPercentCodeBelowItsImplicitMinimumIsBelowMinimumAndAtItGivesOneGrosz(): void
    {
        $below = BasketQuote::of([self::line(self::A, 1)], [self::A => self::price(9)], self::percentCode(10), 0, self::now());
        $at = BasketQuote::of([self::line(self::A, 1)], [self::A => self::price(10)], self::percentCode(10), 0, self::now());

        self::assertEquals(DiscountCodeRejection::belowMinimum(10), $below->rejection());
        self::assertSame(0, $below->discountGross()->amount());
        self::assertSame(1, $at->discountGross()->amount());
        self::assertSame(9, $at->gross()->amount());
    }

    public function testAnUnknownCodeIsInvalidAndGivesNoDiscount(): void
    {
        $quote = BasketQuote::withUnknownCode([self::line(self::A, 1)], [self::A => self::price(5000)]);

        self::assertEquals(DiscountCodeRejection::invalid(), $quote->rejection());
        self::assertNull($quote->discount());
        self::assertSame([5000, 0, 4065, 935, 5000], self::totals($quote));
    }

    /**
     * @param \Closure(): list<BasketLine> $lines
     */
    #[DataProvider('invalidBaskets')]
    public function testABasketOutsideItsLimitsIsRefused(\Closure $lines): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BasketQuote::of($lines(), [], null, 0, self::now());
    }

    /** @return iterable<string, array{\Closure(): list<BasketLine>}> */
    public static function invalidBaskets(): iterable
    {
        yield 'no line' => [static fn (): array => []];
        yield '51 lines' => [static fn (): array => array_map(static fn (int $i): BasketLine => self::line(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1), range(0, 50))];
        yield 'a variant twice' => [static fn (): array => [self::line(self::A, 1), self::line(self::A, 2)]];
    }

    #[DataProvider('invalidQuantities')]
    public function testALineQuantityOutsideOneToNinetyNineIsRefused(int $quantity): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::line(self::A, $quantity);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'a hundred' => [100];
    }

    /**
     * Σ d_i = D, every d_i ≤ g_i, every line gross = g_i − d_i with net + VAT = gross, and the totals the sums of the
     * lines with gross = S − D.
     */
    private static function assertExact(BasketQuote $quote): void
    {
        $discount = 0;
        $net = 0;
        $vat = 0;
        $gross = 0;
        foreach ($quote->lines() as $line) {
            $amounts = $line->amounts();
            self::assertLessThanOrEqual($line->grossBeforeDiscount()->amount(), $line->discount()->amount());
            self::assertSame($line->grossBeforeDiscount()->amount() - $line->discount()->amount(), $amounts->gross()->amount());
            self::assertSame($amounts->gross()->amount(), $amounts->net()->amount() + $amounts->vat()->amount());
            $discount += $line->discount()->amount();
            $net += $amounts->net()->amount();
            $vat += $amounts->vat()->amount();
            $gross += $amounts->gross()->amount();
        }
        self::assertSame($quote->discountGross()->amount(), $discount);
        self::assertSame([$net, $vat, $gross], [$quote->net()->amount(), $quote->vat()->amount(), $quote->gross()->amount()]);
        self::assertSame($quote->subtotalGross()->amount() - $quote->discountGross()->amount(), $gross);
    }

    /**
     * @return list<int>
     */
    private static function discounts(BasketQuote $quote): array
    {
        return array_map(static fn (QuotedLine $line): int => $line->discount()->amount(), $quote->lines());
    }

    /**
     * @return array{int, int, int} gross, net, VAT of the line after the discount
     */
    private static function amounts(QuotedLine $line): array
    {
        return [$line->amounts()->gross()->amount(), $line->amounts()->net()->amount(), $line->amounts()->vat()->amount()];
    }

    /**
     * @return array{int, int, int, int, int} subtotal, discount, net, VAT, gross
     */
    private static function totals(BasketQuote $quote): array
    {
        return [$quote->subtotalGross()->amount(), $quote->discountGross()->amount(), $quote->net()->amount(), $quote->vat()->amount(), $quote->gross()->amount()];
    }

    private static function line(string $variantId, int $quantity): BasketLine
    {
        return BasketLine::of(VariantId::fromString($variantId), $quantity);
    }

    private static function price(int $gross, string $taxClass = 'standard', ?int $promotionPercent = null): BasketPrice
    {
        $rates = new PolishVatRates();
        $class = TaxClass::fromString($taxClass);
        $catalog = VariantPrice::reconstitute(
            VariantId::fromString(self::A),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), $rates->rateFor($class)),
            $class,
            1,
            new \DateTimeImmutable('2026-11-01T00:00:00Z'),
        );
        $percentOff = null === $promotionPercent ? null : PercentOff::of($promotionPercent);
        $effective = EffectivePrice::of($catalog, $rates, $percentOff);
        \assert(null !== $effective);

        return new BasketPrice($effective, $class, null === $percentOff ? null : new BasketPromotion(PromotionId::fromString(self::PROMOTION), 'Jesień', $percentOff));
    }

    private static function percentCode(int $percent, bool $enabled = true, string $startsAt = '2026-11-01T00:00:00Z', ?string $endsAt = null, ?int $limit = null): DiscountCode
    {
        return self::code(DiscountValue::percent(PercentOff::of($percent)), null, $enabled, $startsAt, $endsAt, $limit);
    }

    private static function amountCode(int $amount, int $minimum): DiscountCode
    {
        return self::code(DiscountValue::of(DiscountKind::Amount, $amount), MinimumOrder::of($minimum), true, '2026-11-01T00:00:00Z', null, null);
    }

    private static function code(DiscountValue $value, ?MinimumOrder $minimum, bool $enabled, string $startsAt, ?string $endsAt, ?int $limit): DiscountCode
    {
        return DiscountCode::create(
            DiscountCodeId::fromString(self::CODE_ID),
            DiscountCodeValue::of('witaj10'),
            $value,
            $minimum,
            ValidityPeriod::of(new \DateTimeImmutable($startsAt), null === $endsAt ? null : new \DateTimeImmutable($endsAt)),
            null === $limit ? null : UsageLimit::of($limit),
            $enabled,
            null,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
