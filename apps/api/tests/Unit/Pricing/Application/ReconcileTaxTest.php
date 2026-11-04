<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\TaxReconciliation\ReconcileTax;
use App\Pricing\Application\TaxReconciliation\TaxDiscrepancy;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceHistoryEntry;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\ConcurrentlyEditedVariantPriceRepository;
use App\Tests\Unit\Pricing\FixedVatRates;
use App\Tests\Unit\Pricing\InMemoryPersistenceSession;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use App\Tests\Unit\Pricing\InMemoryVariantsForPricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReconcileTaxTest extends TestCase
{
    private const string FIRST = '00000000-0000-4000-8000-000000000001';
    private const string SECOND = '00000000-0000-4000-8000-000000000002';
    private const string THIRD = '00000000-0000-4000-8000-000000000003';

    private InMemoryVariantPriceRepository $prices;
    private InMemoryPersistenceSession $session;
    private ConcurrentlyEditedVariantPriceRepository $repository;
    private FixedVatRates $rates;

    protected function setUp(): void
    {
        // energy_gel was 5% when the prices were stored and is 8% now.
        $this->rates = new FixedVatRates(['standard' => 2300, 'printed_book' => 500, 'energy_gel' => 800]);
        $this->prices = new InMemoryVariantPriceRepository();
        $this->session = new InMemoryPersistenceSession();
        $this->repository = new ConcurrentlyEditedVariantPriceRepository($this->prices, $this->session, $this->rates);
    }

    public function testConsistentPricesGiveNoDiscrepancy(): void
    {
        $this->storePrice(self::FIRST, 12300, 'standard', 2300);
        $this->storePrice(self::SECOND, 5490, 'printed_book', 500);

        [$discrepancies, $checked] = $this->reconcile(['printed_book' => [self::SECOND]], apply: true);

        self::assertSame([], $discrepancies);
        self::assertSame(2, $checked);
        self::assertSame(0, $this->prices->writes);
    }

    public function testWithoutApplyReportsAnOutdatedRateAndAClassOtherThanInCatalogAndChangesNothing(): void
    {
        $this->storePrice(self::FIRST, 1290, 'energy_gel', 500);
        $this->storePrice(self::SECOND, 5490, 'standard', 2300);
        $this->storePrice(self::THIRD, 12300, 'standard', 2300);

        [$discrepancies, $checked] = $this->reconcile(['energy_gel' => [self::FIRST], 'printed_book' => [self::SECOND]], apply: false);

        self::assertSame(
            [
                [self::FIRST, 'energy_gel', 500, 'energy_gel', 800, 'found'],
                [self::SECOND, 'standard', 2300, 'printed_book', 500, 'found'],
            ],
            array_map($this->summary(...), $discrepancies),
        );
        self::assertSame(3, $checked);
        self::assertSame(0, $this->prices->writes);
        self::assertSame([], $this->prices->history);
        self::assertSame(1, $this->storedPrice(self::FIRST)->version());
    }

    public function testApplyRepricesTheSameGrossWithOneEntryEachAndTheNextRunFindsNothing(): void
    {
        $this->storePrice(self::FIRST, 1290, 'energy_gel', 500);
        $this->storePrice(self::SECOND, 5490, 'standard', 2300);
        $catalog = ['energy_gel' => [self::FIRST], 'printed_book' => [self::SECOND]];

        [$discrepancies] = $this->reconcile($catalog, apply: true);

        self::assertSame(
            [
                [self::FIRST, 'energy_gel', 500, 'energy_gel', 800, 'fixed'],
                [self::SECOND, 'standard', 2300, 'printed_book', 500, 'fixed'],
            ],
            array_map($this->summary(...), $discrepancies),
        );
        $gel = $this->storedPrice(self::FIRST);
        self::assertTrue($gel->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(1290), VatRate::fromBasisPoints(800))));
        self::assertSame(2, $gel->version());
        $book = $this->storedPrice(self::SECOND);
        self::assertTrue($book->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints(500))));
        self::assertSame('printed_book', $book->taxClass()->toString());
        self::assertSame(
            [[self::FIRST, 'energy_gel', 800], [self::SECOND, 'printed_book', 500]],
            array_map(static fn (PriceHistoryEntry $entry): array => [$entry->variantId()->toString(), $entry->taxClass()->toString(), $entry->breakdown()->rate()->basisPoints()], $this->prices->history),
        );
        foreach ($this->prices->history as $entry) {
            self::assertSame(PriceChangeSource::TaxReconciliation, $entry->origin()->source());
            self::assertNull($entry->origin()->actorId());
        }

        self::assertSame([], $this->reconcile($catalog, apply: true)[0]);
        self::assertSame([], $this->reconcile($catalog, apply: false)[0]);
        self::assertSame(2, $this->prices->writes);
    }

    public function testPriceOfAVariantMissingInCatalogKeepsItsClassAndTakesItsCurrentRate(): void
    {
        $this->storePrice(self::FIRST, 1290, 'energy_gel', 500);

        [$discrepancies] = $this->reconcile([], apply: true);

        self::assertSame([[self::FIRST, 'energy_gel', 500, 'energy_gel', 800, 'fixed']], array_map($this->summary(...), $discrepancies));
    }

    /**
     * A class without a rate cannot be repriced: the price is reported and left as it is, the next ones are fixed.
     */
    #[DataProvider('applyOrNot')]
    public function testClassWithoutRateIsReportedAndLeftUnchanged(bool $apply): void
    {
        $this->storePrice(self::FIRST, 12300, 'standard', 2300);
        $this->storePrice(self::SECOND, 1290, 'energy_gel', 500);

        [$discrepancies] = $this->reconcile(['retired_class' => [self::FIRST], 'energy_gel' => [self::SECOND]], $apply);

        self::assertSame(
            [
                [self::FIRST, 'standard', 2300, 'retired_class', null, 'no_rate'],
                [self::SECOND, 'energy_gel', 500, 'energy_gel', 800, $apply ? 'fixed' : 'found'],
            ],
            array_map($this->summary(...), $discrepancies),
        );
        self::assertSame(1, $this->storedPrice(self::FIRST)->version());
        self::assertSame('standard', $this->storedPrice(self::FIRST)->taxClass()->toString());
    }

    /** @return iterable<string, array{bool}> */
    public static function applyOrNot(): iterable
    {
        yield 'report' => [false];
        yield 'apply' => [true];
    }

    /**
     * A staff member changes the gross after the batch was read: the save is refused, the price is read again in a
     * fresh session and repriced from what it is now.
     */
    public function testVersionConflictReadsThePriceAgainAndTriesOnceMore(): void
    {
        $this->storePrice(self::FIRST, 5490, 'standard', 2300);
        $this->repository->editBeforeSave(self::FIRST, 1);

        [$discrepancies] = $this->reconcile(['printed_book' => [self::FIRST]], apply: true);

        self::assertSame([[self::FIRST, 'standard', 2300, 'printed_book', 500, 'fixed']], array_map($this->summary(...), $discrepancies));
        $price = $this->storedPrice(self::FIRST);
        // The gross of the competing change, at the rate of the class from Catalog.
        self::assertTrue($price->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(5590), VatRate::fromBasisPoints(500))));
        self::assertSame('printed_book', $price->taxClass()->toString());
        self::assertSame(3, $price->version());
        self::assertSame([PriceChangeSource::Staff, PriceChangeSource::TaxReconciliation], array_map(static fn (PriceHistoryEntry $entry): PriceChangeSource => $entry->origin()->source(), $this->prices->history));
    }

    /**
     * The competing change already set the current rate (a staff change takes the rate of the class): read again,
     * the price is consistent and nothing is left to report.
     */
    public function testPriceFixedByTheCompetingChangeIsNotReported(): void
    {
        $this->storePrice(self::FIRST, 1290, 'energy_gel', 500);
        $this->repository->editBeforeSave(self::FIRST, 1);

        [$discrepancies, $checked] = $this->reconcile(['energy_gel' => [self::FIRST]], apply: true);

        self::assertSame([], $discrepancies);
        self::assertSame(1, $checked);
        self::assertSame([PriceChangeSource::Staff], array_map(static fn (PriceHistoryEntry $entry): PriceChangeSource => $entry->origin()->source(), $this->prices->history));
    }

    public function testRepeatedConflictIsReportedAndTheNextPricesAreStillFixed(): void
    {
        $this->storePrice(self::FIRST, 5490, 'standard', 2300);
        $this->storePrice(self::SECOND, 1290, 'energy_gel', 500);
        $this->repository->editBeforeSave(self::FIRST, 2);

        [$discrepancies] = $this->reconcile(['printed_book' => [self::FIRST], 'energy_gel' => [self::SECOND]], apply: true);

        self::assertSame(
            [
                [self::FIRST, 'standard', 2300, 'printed_book', 500, 'modified_concurrently'],
                [self::SECOND, 'energy_gel', 500, 'energy_gel', 800, 'fixed'],
            ],
            array_map($this->summary(...), $discrepancies),
        );
        self::assertSame('standard', $this->storedPrice(self::FIRST)->taxClass()->toString());
        self::assertSame(3, $this->storedPrice(self::FIRST)->version());
        self::assertSame(2, $this->storedPrice(self::SECOND)->version());
    }

    /**
     * A short batch is the last one; a full one is followed by another read. Every price is checked against its
     * own variant, while both the prices and the catalog are read in batches; the catalog only as far as the prices
     * need it.
     *
     * @param list<int> $expectedPages
     * @param list<int> $expectedCatalogPages
     */
    #[DataProvider('priceCounts')]
    public function testReadsThePricesAndTheCatalogInBatches(int $count, array $expectedPages, array $expectedCatalogPages): void
    {
        $gels = [];
        for ($i = 1; $i <= $count; ++$i) {
            $id = \sprintf('00000000-0000-4000-8000-%012d', $i);
            // Every third variant belongs to a gel in Catalog, the prices are all `standard`.
            $this->storePrice($id, 1000 + $i, 'standard', 2300);
            if (0 === $i % 3) {
                $gels[] = $id;
            }
        }
        $catalog = new InMemoryVariantsForPricing(array_fill_keys(array_keys($this->allIds($count)), 'slug/standard'), array_fill_keys($gels, 'energy_gel'));
        $reconcile = new ReconcileTax($this->repository, $catalog, $this->rates, $this->session);

        $run = $reconcile(false);
        $found = array_map(static fn (TaxDiscrepancy $discrepancy): string => $discrepancy->variantId->toString(), iterator_to_array($run, false));

        self::assertSame($count, $run->getReturn());
        self::assertSame($gels, $found);
        self::assertSame($expectedPages, $this->prices->pageLimits);
        self::assertSame($expectedCatalogPages, $catalog->pageLimits);
        self::assertSame(\count($expectedPages), $this->session->resets);
    }

    /** @return iterable<string, array{int, list<int>, list<int>}> */
    public static function priceCounts(): iterable
    {
        yield 'no prices' => [0, [500], []];
        yield 'two and a half batches' => [1201, [500, 500, 500], [500, 500, 500]];
        yield 'exactly two batches' => [1000, [500, 500, 500], [500, 500]];
    }

    /**
     * @param array<string, list<string>> $catalog tax class code => variant ids; the variants of the stored prices
     *                                             not listed are absent from Catalog
     *
     * @return array{list<TaxDiscrepancy>, int}
     */
    private function reconcile(array $catalog, bool $apply): array
    {
        $keys = [];
        $classes = [];
        foreach ($catalog as $class => $ids) {
            foreach ($ids as $id) {
                $keys[$id] = 'slug-'.$id.'/standard';
                $classes[$id] = $class;
            }
        }
        $run = new ReconcileTax($this->repository, new InMemoryVariantsForPricing($keys, $classes), $this->rates, $this->session)($apply);
        $discrepancies = iterator_to_array($run, false);

        return [$discrepancies, $run->getReturn()];
    }

    /**
     * @return array<string, true>
     */
    private function allIds(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; ++$i) {
            $ids[\sprintf('00000000-0000-4000-8000-%012d', $i)] = true;
        }

        return $ids;
    }

    /**
     * @return array{string, string, int, string, int|null, string}
     */
    private function summary(TaxDiscrepancy $discrepancy): array
    {
        return [
            $discrepancy->variantId->toString(),
            $discrepancy->storedClass->toString(),
            $discrepancy->storedRate->basisPoints(),
            $discrepancy->expectedClass->toString(),
            $discrepancy->expectedRate?->basisPoints(),
            $discrepancy->outcome->value,
        ];
    }

    private function storePrice(string $id, int $gross, string $taxClass, int $basisPoints): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($id),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            1,
            new \DateTimeImmutable('2026-01-01'),
        ));
    }

    private function storedPrice(string $id): VariantPrice
    {
        $price = $this->prices->find(VariantId::fromString($id));
        self::assertNotNull($price);

        return $price;
    }
}
