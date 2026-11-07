<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\TaxReconciliation\RepriceProductPrices;
use App\Pricing\Application\TaxReconciliation\TaxClassChangedWhileRepricing;
use App\Pricing\Application\TaxReconciliation\TaxDiscrepancy;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceHistoryEntry;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\ConcurrentlyEditedVariantPriceRepository;
use App\Tests\Unit\Pricing\FixedVatRates;
use App\Tests\Unit\Pricing\InMemoryPersistenceSession;
use App\Tests\Unit\Pricing\InMemoryProductDirectory;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepriceProductPricesTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string BLACK = '0192f0c4-7c1a-7c4e-9a6e-000000000011';
    private const string WHITE = '0192f0c4-7c1a-7c4e-9a6e-000000000012';
    /** A variant of the product without a price. */
    private const string GREEN = '0192f0c4-7c1a-7c4e-9a6e-000000000013';

    private InMemoryVariantPriceRepository $prices;
    private InMemoryPersistenceSession $session;
    private ConcurrentlyEditedVariantPriceRepository $repository;
    private FixedVatRates $rates;
    private InMemoryProductDirectory $directory;

    protected function setUp(): void
    {
        $this->rates = new FixedVatRates(['standard' => 2300, 'printed_book' => 500, 'energy_gel' => 800]);
        $this->prices = new InMemoryVariantPriceRepository();
        $this->session = new InMemoryPersistenceSession();
        $this->repository = new ConcurrentlyEditedVariantPriceRepository($this->prices, $this->session, $this->rates);
        $this->directory = new InMemoryProductDirectory()->withVariants(self::PRODUCT, [self::BLACK => 'czarne', self::WHITE => 'białe', self::GREEN => 'zielone']);
    }

    public function testRepricesThePricesOfTheProductToItsNewClassWithTheSameGross(): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);
        $this->storePrice(self::WHITE, 12300, 'standard', 2300);
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book');

        $discrepancies = $this->reprice();

        self::assertEqualsCanonicalizing(
            [
                [self::BLACK, 'standard', 2300, 'printed_book', 500, 'fixed'],
                [self::WHITE, 'standard', 2300, 'printed_book', 500, 'fixed'],
            ],
            array_map($this->summary(...), $discrepancies),
        );
        self::assertSame([5490, 500, 'printed_book', 2], $this->stored(self::BLACK));
        self::assertSame([12300, 500, 'printed_book', 2], $this->stored(self::WHITE));
        $book = $this->storedPrice(self::BLACK);
        self::assertTrue($book->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints(500))));
        self::assertCount(2, $this->prices->history);
        foreach ($this->prices->history as $entry) {
            self::assertSame('printed_book', $entry->taxClass()->toString());
            self::assertSame(PriceChangeSource::TaxReconciliation, $entry->origin()->source());
            self::assertNull($entry->origin()->actorId());
        }
        // The prices of the product's variants in one read; the class read again after the writes.
        self::assertSame([[self::BLACK, self::WHITE, self::GREEN]], $this->prices->manyReads);
        self::assertSame([self::PRODUCT, self::PRODUCT], $this->directory->taxClassRequests);
    }

    public function testPricesAlreadyInTheClassOfTheProductAreNotWritten(): void
    {
        $this->storePrice(self::BLACK, 5490, 'printed_book', 500);
        $this->storePrice(self::WHITE, 12300, 'printed_book', 500);
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book');

        self::assertSame([], $this->reprice());
        self::assertSame(0, $this->prices->writes);
        self::assertSame([], $this->prices->history);
        self::assertSame([self::PRODUCT], $this->directory->taxClassRequests);
    }

    /**
     * Only a price with a class other than the product's is repriced; a stale rate of the right class is left to
     * pricing:reconcile-tax.
     */
    public function testAnOutdatedRateInTheClassOfTheProductIsLeftAsItIs(): void
    {
        $this->storePrice(self::BLACK, 1290, 'energy_gel', 500);
        $this->storePrice(self::WHITE, 1490, 'standard', 2300);
        $this->directory->withTaxClass(self::PRODUCT, 'energy_gel');

        $discrepancies = $this->reprice();

        self::assertSame([[self::WHITE, 'standard', 2300, 'energy_gel', 800, 'fixed']], array_map($this->summary(...), $discrepancies));
        self::assertSame([1290, 500, 'energy_gel', 1], $this->stored(self::BLACK));
        self::assertSame([1490, 800, 'energy_gel', 2], $this->stored(self::WHITE));
    }

    public function testARepeatedEventChangesNothing(): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book');

        $this->reprice();
        $again = $this->reprice();

        self::assertSame([], $again);
        self::assertSame(1, $this->prices->writes);
        self::assertSame([5490, 500, 'printed_book', 2], $this->stored(self::BLACK));
    }

    /**
     * The class goes A (standard) → B (printed_book) → A, and each change publishes an event. An event is handled
     * after its change was saved, with the class Catalog has at that moment, in any order and possibly more than once.
     *
     * @param list<string> $classWhenHandled the class of the product in Catalog when each delivery is handled
     */
    #[DataProvider('deliveriesOfTwoChanges')]
    public function testChangesAToBToAEndWithThePricesInClassA(array $classWhenHandled, int $expectedWrites): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);
        $this->storePrice(self::WHITE, 12300, 'standard', 2300);

        foreach ($classWhenHandled as $class) {
            $this->directory->withTaxClass(self::PRODUCT, $class);
            $this->reprice();
        }

        self::assertSame([5490, 2300, 'standard'], \array_slice($this->stored(self::BLACK), 0, 3));
        self::assertSame([12300, 2300, 'standard'], \array_slice($this->stored(self::WHITE), 0, 3));
        self::assertSame($expectedWrites, $this->prices->writes);
    }

    /** @return iterable<string, array{list<string>, int}> */
    public static function deliveriesOfTwoChanges(): iterable
    {
        yield 'the first while B, then the second' => [['printed_book', 'standard'], 4];
        yield 'the second, then the first, both after the last change' => [['standard', 'standard'], 0];
        yield 'the first while B, the second, the first again' => [['printed_book', 'standard', 'standard'], 4];
        yield 'the first while B twice, then the second twice' => [['printed_book', 'printed_book', 'standard', 'standard'], 4];
    }

    public function testAProductUnknownToCatalogIsHandledWithoutReadingPrices(): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);

        self::assertSame([], $this->reprice('0192f0c4-7c1a-7c4e-9a6e-0000000000ff'));
        self::assertSame([], $this->prices->manyReads);
        self::assertSame(0, $this->prices->writes);
    }

    public function testAClassWithoutRateIsReportedAndThePricesKeepTheirClass(): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);
        $this->storePrice(self::WHITE, 12300, 'standard', 2300);
        $this->directory->withTaxClass(self::PRODUCT, 'retired_class');

        $discrepancies = $this->reprice();

        self::assertEqualsCanonicalizing(
            [
                [self::BLACK, 'standard', 2300, 'retired_class', null, 'no_rate'],
                [self::WHITE, 'standard', 2300, 'retired_class', null, 'no_rate'],
            ],
            array_map($this->summary(...), $discrepancies),
        );
        self::assertSame(0, $this->prices->writes);
        self::assertSame([5490, 2300, 'standard', 1], $this->stored(self::BLACK));
    }

    /**
     * A staff member changes the gross right before the save: the price is read again and repriced from what it is.
     */
    public function testAVersionConflictIsRetriedOnceOnThePriceReadAgain(): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book');
        $this->repository->editBeforeSave(self::BLACK, 1);

        $discrepancies = $this->reprice();

        self::assertSame([[self::BLACK, 'standard', 2300, 'printed_book', 500, 'fixed']], array_map($this->summary(...), $discrepancies));
        self::assertSame([5590, 500, 'printed_book', 3], $this->stored(self::BLACK));
        self::assertSame([PriceChangeSource::Staff, PriceChangeSource::TaxReconciliation], array_map(static fn (PriceHistoryEntry $entry): PriceChangeSource => $entry->origin()->source(), $this->prices->history));
    }

    public function testASecondConflictIsReportedAndTheOtherPricesAreStillRepriced(): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);
        $this->storePrice(self::WHITE, 12300, 'standard', 2300);
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book');
        $this->repository->editBeforeSave(self::BLACK, 2);

        $discrepancies = $this->reprice();

        self::assertEqualsCanonicalizing(
            [
                [self::BLACK, 'standard', 2300, 'printed_book', 500, 'modified_concurrently'],
                [self::WHITE, 'standard', 2300, 'printed_book', 500, 'fixed'],
            ],
            array_map($this->summary(...), $discrepancies),
        );
        self::assertSame([5690, 2300, 'standard', 3], $this->stored(self::BLACK));
        self::assertSame([12300, 500, 'printed_book', 2], $this->stored(self::WHITE));
    }

    /**
     * Another change of the class is saved while the prices are written with the class read before it: its own event
     * may already have been handled (and found the prices in its class), so the run fails for a retry, which
     * reprices them to the class Catalog has then.
     */
    public function testAClassChangedWhileThePricesWereWrittenFailsForARetry(): void
    {
        $this->storePrice(self::BLACK, 5490, 'standard', 2300);
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book')
            ->afterTaxClassRead(function (): void {
                $this->directory->withTaxClass(self::PRODUCT, 'standard');
            });

        try {
            $this->reprice();
            self::fail('Expected TaxClassChangedWhileRepricing.');
        } catch (TaxClassChangedWhileRepricing) {
        }
        self::assertSame([5490, 500, 'printed_book', 2], $this->stored(self::BLACK));

        self::assertSame([[self::BLACK, 'printed_book', 500, 'standard', 2300, 'fixed']], array_map($this->summary(...), $this->reprice()));
        self::assertSame([5490, 2300, 'standard', 3], $this->stored(self::BLACK));
    }

    /**
     * @return list<TaxDiscrepancy>
     */
    private function reprice(string $productId = self::PRODUCT): array
    {
        return new RepriceProductPrices($this->repository, $this->directory, $this->rates, $this->session)(ProductId::fromString($productId));
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

    /**
     * @return array{int, int, string, int} gross, rate in basis points, class and version
     */
    private function stored(string $id): array
    {
        $price = $this->storedPrice($id);

        return [$price->breakdown()->gross()->amount(), $price->breakdown()->rate()->basisPoints(), $price->taxClass()->toString(), $price->version()];
    }

    private function storedPrice(string $id): VariantPrice
    {
        $price = $this->prices->find(VariantId::fromString($id));
        self::assertNotNull($price);

        return $price;
    }
}
