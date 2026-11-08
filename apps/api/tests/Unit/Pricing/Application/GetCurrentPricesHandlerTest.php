<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Query\CurrentEffectivePrices;
use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\GetCurrentPricesHandler;
use App\Pricing\Application\Query\InvalidCurrentPricesQuery;
use App\Pricing\Application\Query\PricePromotion;
use App\Pricing\Application\Query\PriceSnapshot;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryPromotionPrices;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetCurrentPricesHandlerTest extends TestCase
{
    private const string BOOK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string OUTDATED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';
    private const string NO_PRICE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';
    private const string NEW = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a64';
    private const string AUTUMN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string SHOE_WEEK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02';
    private const string NOW = '2026-11-10T12:00:00Z';

    private InMemoryVariantPriceRepository $prices;
    private InMemoryPromotionPrices $promotions;
    private GetCurrentPricesHandler $handler;

    protected function setUp(): void
    {
        $this->prices = new InMemoryVariantPriceRepository();
        $this->promotions = new InMemoryPromotionPrices();
        $this->handler = new GetCurrentPricesHandler(new CurrentEffectivePrices($this->prices, new PolishVatRates(), $this->promotions), $this->promotions, new FixedPricingClock(self::NOW));
        $this->store(self::BOOK, 5490, 'printed_book', 500, 3);
        $this->store(self::SHOES, 12300, 'standard', 2300, 1);
        // Stored at 8%, while the class is at 23% today.
        $this->store(self::OUTDATED, 10800, 'standard', 800, 1);
    }

    public function testReturnsTheStoredBreakdownOfEachVariant(): void
    {
        $snapshots = ($this->handler)(new GetCurrentPrices([self::BOOK, self::SHOES]));

        self::assertEquals([
            new PriceSnapshot(self::BOOK, 'printed_book', 500, 5229, 261, 5490, 'PLN', 3, 5490, null),
            new PriceSnapshot(self::SHOES, 'standard', 2300, 10000, 2300, 12300, 'PLN', 1, 12300, null),
        ], $snapshots);
    }

    public function testAVariantInAnActivePromotionHasThePromotionalGrossWithNetAndVatFromPriceBreakdown(): void
    {
        $this->promotions->promotion(self::AUTUMN, 'Jesień', 20, '2026-11-01T00:00:00Z', '2026-11-20T00:00:00Z', [self::BOOK]);

        $snapshots = ($this->handler)(new GetCurrentPrices([self::BOOK, self::SHOES]));

        // 5490 − floor(5490 × 20 / 100) = 4392 at the 5% of the catalog price: net 4183, VAT 209. The version is the
        // one of the catalog price.
        self::assertEquals([
            new PriceSnapshot(self::BOOK, 'printed_book', 500, 4183, 209, 4392, 'PLN', 3, 5490, new PricePromotion(self::AUTUMN, 'Jesień', 20, new \DateTimeImmutable('2026-11-20T00:00:00Z'), null)),
            new PriceSnapshot(self::SHOES, 'standard', 2300, 10000, 2300, 12300, 'PLN', 1, 12300, null),
        ], $snapshots);
        self::assertEquals([[[self::BOOK, self::SHOES], new \DateTimeImmutable(self::NOW)]], $this->promotions->activeReads);
    }

    public function testWithoutTheFlagNoOmnibusPriceIsComputedAndNoHistoryIsRead(): void
    {
        $this->promotions
            ->promotion(self::AUTUMN, 'Jesień', 20, '2026-11-01T00:00:00Z', '2026-11-20T00:00:00Z', [self::BOOK])
            ->price(self::BOOK, '2026-10-01T00:00:00Z', 5490);

        $snapshots = ($this->handler)(new GetCurrentPrices([self::BOOK]));

        self::assertNull($snapshots[0]->promotion?->lowestPriorGross);
        self::assertSame([[], []], [$this->promotions->priceReads, $this->promotions->promotionReads]);
    }

    public function testWithTheFlagTheOmnibusPriceOfEveryPromotedVariantFromOneReadOfEach(): void
    {
        $this->store(self::NEW, 2000, 'standard', 2300, 1);
        $this->promotions
            ->promotion(self::AUTUMN, 'Jesień', 20, '2026-11-01T00:00:00Z', '2026-11-20T00:00:00Z', [self::BOOK, self::NEW])
            ->promotion(self::SHOE_WEEK, 'Buty', 10, '2026-11-09T00:00:00Z', '2026-11-12T00:00:00Z', [self::SHOES])
            // An earlier promotion of the book (in its window from 2026-10-02) and of the shoes (in theirs from 2026-10-10).
            ->promotion('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'Wcześniej', 10, '2026-10-05T00:00:00Z', '2026-10-10T00:00:00Z', [self::BOOK])
            ->promotion('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b04', 'Wyprzedaż', 50, '2026-10-01T00:00:00Z', '2026-11-01T00:00:00Z', [self::SHOES])
            ->price(self::BOOK, '2026-09-01T00:00:00Z', 6000)
            ->price(self::BOOK, '2026-10-20T00:00:00Z', 5490)
            ->price(self::SHOES, '2026-10-01T00:00:00Z', 12300);
        // NO_PRICE got its price after the start of its promotion.

        $snapshots = ($this->handler)(new GetCurrentPrices([self::BOOK, self::SHOES, self::NEW, self::OUTDATED], withLowestPriorPrice: true));

        self::assertSame(
            [self::BOOK => 5400, self::SHOES => 6150, self::NEW => null],
            array_combine(
                array_map(static fn (PriceSnapshot $s): string => $s->variantId, $snapshots),
                array_map(static fn (PriceSnapshot $s): ?int => $s->promotion?->lowestPriorGross, $snapshots),
            ),
        );
        self::assertSame([4392, 11070, 1600], array_map(static fn (PriceSnapshot $s): int => $s->gross, $snapshots));
        self::assertCount(1, $this->promotions->priceReads);
        self::assertEqualsCanonicalizing([self::BOOK, self::SHOES, self::NEW], $this->promotions->priceReads[0]);
        self::assertCount(1, $this->promotions->promotionReads);
        self::assertEqualsCanonicalizing([self::BOOK, self::SHOES, self::NEW], $this->promotions->promotionReads[0]);
    }

    public function testWithTheFlagAndNoPromotedVariantNoHistoryIsRead(): void
    {
        $snapshots = ($this->handler)(new GetCurrentPrices([self::BOOK, self::SHOES], withLowestPriorPrice: true));

        self::assertSame([null, null], array_map(static fn (PriceSnapshot $s): ?PricePromotion => $s->promotion, $snapshots));
        self::assertSame([[], []], [$this->promotions->priceReads, $this->promotions->promotionReads]);
    }

    public function testOutsideThePeriodOfThePromotionTheCatalogPrice(): void
    {
        $this->promotions
            ->promotion(self::AUTUMN, 'Planowana', 20, '2026-11-10T12:00:01Z', '2026-11-20T00:00:00Z', [self::BOOK])
            ->promotion(self::SHOE_WEEK, 'Zakończona', 10, '2026-11-01T00:00:00Z', self::NOW, [self::SHOES]);

        $snapshots = ($this->handler)(new GetCurrentPrices([self::BOOK, self::SHOES], withLowestPriorPrice: true));

        self::assertEquals([
            new PriceSnapshot(self::BOOK, 'printed_book', 500, 5229, 261, 5490, 'PLN', 3, 5490, null),
            new PriceSnapshot(self::SHOES, 'standard', 2300, 10000, 2300, 12300, 'PLN', 1, 12300, null),
        ], $snapshots);
    }

    public function testAPromotionStartingNowGivesThePromotionalPrice(): void
    {
        $this->promotions->promotion(self::AUTUMN, 'Od teraz', 10, self::NOW, '2026-11-20T00:00:00Z', [self::SHOES]);

        self::assertSame(11070, ($this->handler)(new GetCurrentPrices([self::SHOES]))[0]->gross);
    }

    public function testAVariantInAPromotionWithAnOutdatedRateHasNoPrice(): void
    {
        $this->promotions->promotion(self::AUTUMN, 'Jesień', 20, '2026-11-01T00:00:00Z', '2026-11-20T00:00:00Z', [self::OUTDATED]);

        self::assertSame([], ($this->handler)(new GetCurrentPrices([self::OUTDATED], withLowestPriorPrice: true)));
    }

    public function testWithoutACurrentPriceNoPromotionIsRead(): void
    {
        self::assertSame([], ($this->handler)(new GetCurrentPrices([self::OUTDATED, self::NO_PRICE])));
        self::assertSame([], $this->promotions->activeReads);
    }

    public function testSkipsPricesWithAnOutdatedRateAndVariantsWithoutPrice(): void
    {
        $snapshots = ($this->handler)(new GetCurrentPrices([self::OUTDATED, self::NO_PRICE, self::BOOK]));

        self::assertSame([self::BOOK], array_map(static fn (PriceSnapshot $s): string => $s->variantId, $snapshots));
    }

    public function testKeepsTheOrderOfTheRequestAndAnswersADuplicatedIdOnce(): void
    {
        $snapshots = ($this->handler)(new GetCurrentPrices([self::SHOES, self::BOOK, strtoupper(self::SHOES)]));

        self::assertSame([self::SHOES, self::BOOK], array_map(static fn (PriceSnapshot $s): string => $s->variantId, $snapshots));
    }

    public function testAcceptsOneAnd4800Ids(): void
    {
        self::assertCount(1, ($this->handler)(new GetCurrentPrices([self::BOOK])));

        $ids = array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 4799));
        $ids[] = self::BOOK;
        self::assertSame([self::BOOK], array_map(static fn (PriceSnapshot $s): string => $s->variantId, ($this->handler)(new GetCurrentPrices($ids))));
    }

    /** @param list<string> $ids */
    #[DataProvider('rejectedIds')]
    public function testRejectsAnEmptyListMoreThan4800IdsAndANonUuid(array $ids): void
    {
        $this->expectException(InvalidCurrentPricesQuery::class);

        ($this->handler)(new GetCurrentPrices($ids));
    }

    public function testTheRejectionIsInvalidInputWithoutTheSubmittedValue(): void
    {
        try {
            ($this->handler)(new GetCurrentPrices(['not-a-secret-uuid']));
            self::fail('Expected InvalidCurrentPricesQuery.');
        } catch (InvalidCurrentPricesQuery $e) {
            self::assertContains(InvalidInput::class, class_implements($e));
            self::assertStringNotContainsString('not-a-secret-uuid', $e->getMessage());
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function rejectedIds(): iterable
    {
        yield 'empty' => [[]];
        yield '4801 ids' => [array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 4801))];
        yield 'not a uuid' => [[self::BOOK, 'nope']];
    }

    private function store(string $id, int $gross, string $taxClass, int $basisPoints, int $version): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($id),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            $version,
            new \DateTimeImmutable(),
        ));
    }
}
