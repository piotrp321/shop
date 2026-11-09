<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Query\CurrentEffectivePrices;
use App\Pricing\Application\Query\InvalidBasketQuery;
use App\Pricing\Application\Query\QuoteBasket;
use App\Pricing\Application\Query\QuoteBasketHandler;
use App\Pricing\Application\Query\QuoteBasketLine;
use App\Pricing\Application\Query\QuotedBasket;
use App\Pricing\Application\Query\QuotedBasketLine;
use App\Pricing\Application\Query\QuotedDiscount;
use App\Pricing\Application\Query\QuotedLinePromotion;
use App\Pricing\Application\Query\QuotedRejection;
use App\Pricing\Application\Query\QuotedUnitPrice;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeRejection;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\OrderId;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeRepository;
use App\Tests\Unit\Pricing\InMemoryDiscountCodeUsages;
use App\Tests\Unit\Pricing\InMemoryPromotionPrices;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use App\Tests\Unit\Pricing\PricingJournal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteBasketHandlerTest extends TestCase
{
    private const string BOOK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string NO_PRICE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';
    private const string AUTUMN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string CODE_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c01';
    private const string NOW = '2026-11-10T12:00:00Z';

    private InMemoryVariantPriceRepository $prices;
    private InMemoryPromotionPrices $promotions;
    private InMemoryDiscountCodeRepository $codes;
    private PricingJournal $journal;
    private InMemoryDiscountCodeUsages $usages;
    private QuoteBasketHandler $handler;

    protected function setUp(): void
    {
        $this->prices = new InMemoryVariantPriceRepository();
        $this->promotions = new InMemoryPromotionPrices();
        $this->codes = new InMemoryDiscountCodeRepository();
        $this->journal = new PricingJournal();
        $this->usages = new InMemoryDiscountCodeUsages($this->journal);
        $this->handler = new QuoteBasketHandler(
            new CurrentEffectivePrices($this->prices, new PolishVatRates(), $this->promotions),
            $this->codes,
            $this->usages,
            new FixedPricingClock(self::NOW),
        );
        $this->store(self::BOOK, 5490, 'printed_book', 500);
        $this->store(self::SHOES, 12300, 'standard', 2300);
    }

    public function testWithoutACodeTheLinesHaveTheirEffectivePricesAndValuesAndTheTotalsTheirSums(): void
    {
        $quote = $this->quote([self::line(self::SHOES, 2), self::line(self::NO_PRICE, 1), self::line(self::BOOK, 1)]);

        self::assertEquals(new QuotedBasket(
            [
                new QuotedBasketLine(self::SHOES, 2, new QuotedUnitPrice(10000, 2300, 12300, 2300, 'standard'), 12300, null, 24600, 0, 20000, 4600, 24600),
                new QuotedBasketLine(self::BOOK, 1, new QuotedUnitPrice(5229, 261, 5490, 500, 'printed_book'), 5490, null, 5490, 0, 5229, 261, 5490),
            ],
            30090,
            0,
            25229,
            4861,
            30090,
            'PLN',
            null,
            null,
        ), $quote);
        self::assertEquals([[[self::SHOES, self::BOOK], new \DateTimeImmutable(self::NOW)]], $this->promotions->activeReads, 'the promotions at the clock');
    }

    public function testAPromotedLineCarriesThePromotion(): void
    {
        $this->promotions->promotion(self::AUTUMN, 'Jesień', 20, '2026-11-01T00:00:00Z', '2026-11-20T00:00:00Z', [self::BOOK]);

        $line = $this->quote([self::line(self::BOOK, 1)])->lines[0];

        self::assertEquals(new QuotedUnitPrice(4183, 209, 4392, 500, 'printed_book'), $line->unit);
        self::assertSame(5490, $line->regularUnitGross);
        self::assertEquals(new QuotedLinePromotion(self::AUTUMN, 'Jesień', 20), $line->promotion);
        self::assertSame(4392, $line->grossBeforeDiscount);
    }

    public function testACodeIsFoundByItsTextInAnyCaseAndTrimmedAndItsDiscountIsAllocated(): void
    {
        $this->codes->store(self::code(DiscountValue::of(DiscountKind::Percent, 10)));

        $quote = $this->quote([self::line(self::SHOES, 2), self::line(self::BOOK, 1)], "\u{00A0} witaj10 ");

        self::assertEquals(new QuotedDiscount(self::CODE_ID, 'WITAJ10', 'percent', 10, 3009), $quote->discount);
        self::assertNull($quote->rejection);
        self::assertSame([30090, 3009, 27081], [$quote->subtotalGross, $quote->discountGross, $quote->gross]);
        // 24 600 × 3009 / 30 090 = 2460 r 0, 5490 × 3009 / 30 090 = 549 r 0.
        self::assertSame([2460, 549], array_map(static fn (QuotedBasketLine $line): int => $line->discountGross, $quote->lines));
        self::assertSame([22140, 4941], array_map(static fn (QuotedBasketLine $line): int => $line->gross, $quote->lines));
        self::assertSame($quote->net + $quote->vat, $quote->gross);
        self::assertSame([], $this->journal->entries, 'the active usages of a code without a limit are not counted');
    }

    public function testTheActiveUsagesAreCountedForACodeWithALimit(): void
    {
        $this->codes->store(self::code(DiscountValue::of(DiscountKind::Percent, 10), limit: 2));
        $this->use(1);

        $applied = $this->quote([self::line(self::SHOES, 1)], 'WITAJ10');
        $this->use(2);
        $usedUp = $this->quote([self::line(self::SHOES, 1)], 'WITAJ10');

        self::assertNotNull($applied->discount);
        self::assertSame(['active uses', 'active uses'], $this->journal->entries);
        self::assertNull($usedUp->discount);
        self::assertEquals(new QuotedRejection('WITAJ10', 'used_up', DiscountCodeRejection::TYPE_USED_UP, 'Ten kod został już wykorzystany.', null), $usedUp->rejection);
        self::assertSame([12300, 0, 12300], [$usedUp->subtotalGross, $usedUp->discountGross, $usedUp->gross]);
    }

    public function testACodeBelowItsMinimumIsRejectedWithTheMinimumAndItsDetail(): void
    {
        $this->codes->store(self::code(DiscountValue::of(DiscountKind::Amount, 5000), minimum: 30000));

        $quote = $this->quote([self::line(self::SHOES, 2)], 'witaj10');

        self::assertEquals(new QuotedRejection('WITAJ10', 'below_minimum', DiscountCodeRejection::TYPE_BELOW_MINIMUM, 'Ten kod działa przy zamówieniu od 300,00 zł.', 30000), $quote->rejection);
        self::assertNull($quote->discount);
        self::assertSame(24600, $quote->gross);
    }

    public function testAnAppliedAmountCodeHasItsKindAndAmount(): void
    {
        $this->codes->store(self::code(DiscountValue::of(DiscountKind::Amount, 5000), minimum: 30000));

        $quote = $this->quote([self::line(self::SHOES, 3)], 'WITAJ10');

        self::assertEquals(new QuotedDiscount(self::CODE_ID, 'WITAJ10', 'amount', 5000, 5000), $quote->discount);
        self::assertSame([36900, 5000, 31900], [$quote->subtotalGross, $quote->discountGross, $quote->gross]);
    }

    public function testADisabledCodeIsInvalid(): void
    {
        $this->codes->store(self::code(DiscountValue::of(DiscountKind::Percent, 10), enabled: false));

        $quote = $this->quote([self::line(self::SHOES, 1)], 'WITAJ10');

        self::assertEquals(new QuotedRejection('WITAJ10', 'invalid', DiscountCodeRejection::TYPE_INVALID, 'Ten kod jest nieprawidłowy albo nieaktywny.', null), $quote->rejection);
    }

    /**
     * @return iterable<string, array{string, string}> the text, the code the rejection names
     */
    public static function unknownCodes(): iterable
    {
        yield 'no such code' => ['niema-takiego', 'NIEMA-TAKIEGO'];
        yield 'too short' => ['ab', 'AB'];
        yield 'too long for a code' => [str_repeat('a', 33), str_repeat('A', 33)];
        yield 'the longest text' => [str_repeat('a', 64), str_repeat('A', 64)];
        yield 'a space inside' => ['witaj 10', 'WITAJ 10'];
        yield 'a Polish letter' => ['jesień', 'JESIEń'];
        yield 'a NUL' => ["WITAJ\u{0000}10", "WITAJ\u{0000}10"];
        yield 'a line separator' => ["WITAJ\u{2028}10", "WITAJ\u{2028}10"];
    }

    #[DataProvider('unknownCodes')]
    public function testATextNoCodeHasIsAnInvalidCodeNotAnError(string $text, string $named): void
    {
        $this->codes->store(self::code(DiscountValue::of(DiscountKind::Percent, 10)));

        $quote = $this->quote([self::line(self::SHOES, 1)], $text);

        self::assertEquals(new QuotedRejection($named, 'invalid', DiscountCodeRejection::TYPE_INVALID, 'Ten kod jest nieprawidłowy albo nieaktywny.', null), $quote->rejection);
        self::assertNull($quote->discount);
        self::assertSame(12300, $quote->gross);
    }

    public function testATextThatIsNotUtf8IsAnInvalidCodeNamedWithItsBadBytesReplaced(): void
    {
        $quote = $this->quote([self::line(self::SHOES, 1)], "witaj\xFF");

        self::assertEquals(new QuotedRejection('WITAJ?', 'invalid', DiscountCodeRejection::TYPE_INVALID, 'Ten kod jest nieprawidłowy albo nieaktywny.', null), $quote->rejection);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function noCodes(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'Unicode spaces only' => ["\u{00A0}\u{2003}\t"];
    }

    #[DataProvider('noCodes')]
    public function testABlankCodeIsNoCode(?string $text): void
    {
        $quote = $this->quote([self::line(self::SHOES, 1)], $text);

        self::assertNull($quote->discount);
        self::assertNull($quote->rejection);
        self::assertSame(12300, $quote->gross);
    }

    public function testATextLongerThanSixtyFourCharactersIsAnInvalidQuery(): void
    {
        $this->expectException(InvalidBasketQuery::class);

        $this->quote([self::line(self::SHOES, 1)], str_repeat('ą', 65));
    }

    /**
     * @return iterable<string, array{\Closure(): list<QuoteBasketLine>}>
     */
    public static function invalidLines(): iterable
    {
        yield 'no line' => [static fn (): array => []];
        yield '51 lines' => [static fn (): array => array_map(static fn (int $i): QuoteBasketLine => self::line(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1), range(0, 50))];
        yield 'not a UUID' => [static fn (): array => [self::line('not-a-uuid', 1)]];
        yield 'no unit' => [static fn (): array => [self::line(self::SHOES, 0)]];
        yield 'a hundred units' => [static fn (): array => [self::line(self::SHOES, 100)]];
        yield 'a variant twice' => [static fn (): array => [self::line(self::SHOES, 1), self::line(strtoupper(self::SHOES), 1)]];
    }

    /**
     * @param \Closure(): list<QuoteBasketLine> $lines
     */
    #[DataProvider('invalidLines')]
    public function testLinesOutsideTheLimitsAreAnInvalidQuery(\Closure $lines): void
    {
        $this->expectException(InvalidBasketQuery::class);

        $this->quote($lines());
    }

    public function testFiftyLinesAreQuoted(): void
    {
        $lines = array_map(static fn (int $i): QuoteBasketLine => self::line(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 99), range(0, 49));

        self::assertSame([], $this->quote($lines)->lines, 'variants without a price are left out');
    }

    /**
     * @param list<QuoteBasketLine> $lines
     */
    private function quote(array $lines, ?string $code = null): QuotedBasket
    {
        return ($this->handler)(new QuoteBasket($lines, $code));
    }

    private static function line(string $variantId, int $quantity): QuoteBasketLine
    {
        return new QuoteBasketLine($variantId, $quantity);
    }

    private function use(int $order): void
    {
        $this->usages->add(DiscountCodeUsage::record(
            DiscountCodeUsageId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', 900 + $order)),
            DiscountCodeId::fromString(self::CODE_ID),
            OrderId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', 800 + $order)),
            new \DateTimeImmutable('2026-11-09T00:00:00Z'),
        ));
    }

    private static function code(DiscountValue $value, ?int $minimum = null, ?int $limit = null, bool $enabled = true): DiscountCode
    {
        return DiscountCode::create(
            DiscountCodeId::fromString(self::CODE_ID),
            DiscountCodeValue::of('WITAJ10'),
            $value,
            null === $minimum ? null : MinimumOrder::of($minimum),
            ValidityPeriod::of(new \DateTimeImmutable('2026-11-01T00:00:00Z'), null),
            null === $limit ? null : UsageLimit::of($limit),
            $enabled,
            null,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }

    private function store(string $id, int $gross, string $taxClass, int $basisPoints): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($id),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            1,
            new \DateTimeImmutable(),
        ));
    }
}
