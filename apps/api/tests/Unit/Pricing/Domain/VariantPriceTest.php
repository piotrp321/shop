<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceHistoryEntry;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceChanged;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\FixedVatRates;
use PHPUnit\Framework\TestCase;

final class VariantPriceTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testCreateComputesTheBreakdownAndRecordsOneEntry(): void
    {
        $before = new \DateTimeImmutable();
        $price = VariantPrice::create(
            VariantId::fromString(self::ID),
            GrossPrice::pln(5490),
            TaxClass::fromString('printed_book'),
            new PolishVatRates(),
            PriceChangeOrigin::staff('sub-1', 'Anna Admin'),
        );
        $after = new \DateTimeImmutable();

        self::assertSame(self::ID, $price->id()->toString());
        self::assertSame(1, $price->version());
        self::assertSame('printed_book', $price->taxClass()->toString());
        self::assertTrue($price->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints(500))));

        $entries = $price->releaseHistory();
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(self::ID, $entry->variantId()->toString());
        self::assertSame(5490, $entry->breakdown()->gross()->amount());
        self::assertSame(5229, $entry->breakdown()->net()->amount());
        self::assertSame(261, $entry->breakdown()->vat()->amount());
        self::assertSame(500, $entry->breakdown()->rate()->basisPoints());
        self::assertSame('printed_book', $entry->taxClass()->toString());
        self::assertSame(PriceChangeSource::Staff, $entry->origin()->source());
        self::assertSame('sub-1', $entry->origin()->actorId());
        self::assertSame('Anna Admin', $entry->origin()->actorName());
        self::assertGreaterThanOrEqual($before, $entry->changedAt());
        self::assertLessThanOrEqual($after, $entry->changedAt());
        self::assertEquals($entry->changedAt(), $price->updatedAt());
        self::assertSame([], $price->releaseHistory());
    }

    public function testCreateRefusesAClassWithoutRate(): void
    {
        $this->expectException(TaxClassWithoutRate::class);

        VariantPrice::create(VariantId::fromString(self::ID), GrossPrice::pln(100), TaxClass::fromString('luxury'), new PolishVatRates(), PriceChangeOrigin::seed());
    }

    public function testReconstituteRecordsNothing(): void
    {
        $price = $this->stored(version: 7);

        self::assertSame(7, $price->version());
        self::assertSame(10000, $price->breakdown()->gross()->amount());
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:00:00+00:00'), $price->updatedAt());
        self::assertSame([], $price->releaseHistory());
    }

    public function testChangeGrossToTheSameAmountChangesNothing(): void
    {
        $price = $this->stored(version: 3);

        self::assertFalse($price->changeGross(GrossPrice::pln(10000), new PolishVatRates(), PriceChangeOrigin::seed()));

        self::assertSame(3, $price->version());
        self::assertSame([], $price->releaseHistory());
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:00:00+00:00'), $price->updatedAt());
    }

    public function testChangeGrossRecordsOneEntryAndANewVersion(): void
    {
        $price = $this->stored(version: 3);

        self::assertTrue($price->changeGross(GrossPrice::pln(12300), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'Anna Admin')));

        self::assertSame(4, $price->version());
        self::assertSame(10000, $price->breakdown()->net()->amount());
        $entries = $price->releaseHistory();
        self::assertCount(1, $entries);
        self::assertSame(12300, $entries[0]->breakdown()->gross()->amount());
        self::assertSame(2300, $entries[0]->breakdown()->vat()->amount());
        self::assertSame('standard', $entries[0]->taxClass()->toString());
        self::assertGreaterThan(new \DateTimeImmutable('2026-10-01 12:00:00+00:00'), $price->updatedAt());
    }

    public function testChangeGrossToTheSameAmountWithAChangedRateRecordsOneEntry(): void
    {
        $price = $this->stored(version: 3);

        $changed = $price->changeGross(GrossPrice::pln(10000), new FixedVatRates(['standard' => 800]), PriceChangeOrigin::seed());

        self::assertTrue($changed);
        self::assertSame(4, $price->version());
        self::assertSame(800, $price->breakdown()->rate()->basisPoints());
        self::assertSame(9259, $price->breakdown()->net()->amount());
        self::assertCount(1, $price->releaseHistory());
    }

    public function testChangesBeforeTheNextSaveShareOneVersionStep(): void
    {
        $price = $this->stored(version: 3);
        $rates = new PolishVatRates();

        $price->changeGross(GrossPrice::pln(11000), $rates, PriceChangeOrigin::seed());
        $price->changeGross(GrossPrice::pln(12000), $rates, PriceChangeOrigin::seed());

        self::assertSame(4, $price->version());
        self::assertSame(
            [11000, 12000],
            array_map(static fn (PriceHistoryEntry $entry): int => $entry->breakdown()->gross()->amount(), $price->releaseHistory()),
        );

        $price->changeGross(GrossPrice::pln(13000), $rates, PriceChangeOrigin::seed());
        self::assertSame(5, $price->version());
    }

    public function testRepriceWithTheSameClassAndRateChangesNothing(): void
    {
        $price = $this->stored(version: 2);

        self::assertFalse($price->reprice(TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::taxReconciliation()));

        self::assertSame(2, $price->version());
        self::assertSame([], $price->releaseHistory());
    }

    public function testRepriceKeepsTheGrossAndAppliesTheRateOfTheNewClass(): void
    {
        $price = $this->stored(version: 2);

        self::assertTrue($price->reprice(TaxClass::fromString('printed_book'), new PolishVatRates(), PriceChangeOrigin::taxReconciliation()));

        self::assertSame(3, $price->version());
        self::assertSame('printed_book', $price->taxClass()->toString());
        self::assertSame(10000, $price->breakdown()->gross()->amount());
        self::assertSame(9524, $price->breakdown()->net()->amount());
        $entries = $price->releaseHistory();
        self::assertCount(1, $entries);
        self::assertSame(PriceChangeSource::TaxReconciliation, $entries[0]->origin()->source());
        self::assertNull($entries[0]->origin()->actorId());
        self::assertSame('printed_book', $entries[0]->taxClass()->toString());
    }

    public function testRepriceToAnotherClassWithTheSameRateIsAChange(): void
    {
        $price = VariantPrice::reconstitute(
            VariantId::fromString(self::ID),
            PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints(500)),
            TaxClass::fromString('printed_book'),
            1,
            new \DateTimeImmutable('2026-10-01 12:00:00+00:00'),
        );

        self::assertTrue($price->reprice(TaxClass::fromString('printed_map'), new PolishVatRates(), PriceChangeOrigin::taxReconciliation()));

        self::assertSame(2, $price->version());
        self::assertTrue($price->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints(500))));
        self::assertCount(1, $price->releaseHistory());
    }

    public function testCreateRecordsAPriceChangeWithItsSource(): void
    {
        $before = new \DateTimeImmutable();
        $price = VariantPrice::create(VariantId::fromString(self::ID), GrossPrice::pln(5490), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::seed());
        $after = new \DateTimeImmutable();

        $events = $price->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(VariantPriceChanged::class, $events[0]);
        self::assertSame(self::ID, $events[0]->variantId);
        self::assertSame(PriceChangeSource::Seed, $events[0]->source);
        self::assertGreaterThanOrEqual($before, $events[0]->occurredAt());
        self::assertLessThanOrEqual($after, $events[0]->occurredAt());
        self::assertSame([], $price->releaseEvents());
    }

    public function testReconstituteRecordsNoPriceChange(): void
    {
        self::assertSame([], $this->stored(version: 2)->releaseEvents());
    }

    public function testChangeGrossRecordsAPriceChangeWithTheSourceOfTheChange(): void
    {
        $price = $this->stored(version: 3);

        $price->changeGross(GrossPrice::pln(12300), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'Anna Admin'));

        $events = $price->releaseEvents();
        self::assertCount(1, $events);
        self::assertSame(self::ID, $events[0]->variantId);
        self::assertSame(PriceChangeSource::Staff, $events[0]->source);
        self::assertEquals($price->updatedAt(), $events[0]->occurredAt());
    }

    public function testRepriceRecordsAPriceChangeFromTheTaxReconciliation(): void
    {
        $price = $this->stored(version: 2);

        $price->reprice(TaxClass::fromString('printed_book'), new PolishVatRates(), PriceChangeOrigin::taxReconciliation());

        $events = $price->releaseEvents();
        self::assertCount(1, $events);
        self::assertSame(PriceChangeSource::TaxReconciliation, $events[0]->source);
    }

    public function testAChangeToTheSamePriceRecordsNoPriceChange(): void
    {
        $price = $this->stored(version: 3);

        $price->changeGross(GrossPrice::pln(10000), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'Anna Admin'));
        $price->reprice(TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::taxReconciliation());

        self::assertSame([], $price->releaseEvents());
    }

    public function testChangesBeforeTheNextSaveShareOnePriceChangeWithTheLastSource(): void
    {
        $price = $this->stored(version: 3);
        $rates = new PolishVatRates();

        $price->changeGross(GrossPrice::pln(11000), $rates, PriceChangeOrigin::seed());
        $price->reprice(TaxClass::fromString('printed_book'), $rates, PriceChangeOrigin::taxReconciliation());

        $events = $price->releaseEvents();
        self::assertCount(1, $events);
        self::assertSame(PriceChangeSource::TaxReconciliation, $events[0]->source);

        $price->changeGross(GrossPrice::pln(13000), $rates, PriceChangeOrigin::staff('sub-1', 'Anna Admin'));
        self::assertCount(1, $price->releaseEvents());
    }

    public function testIsCurrentWhenTheStoredRateIsTheRateOfItsClass(): void
    {
        $price = $this->stored(version: 1);

        self::assertTrue($price->isCurrent(new PolishVatRates()));
        self::assertFalse($price->isCurrent(new FixedVatRates(['standard' => 800])));
        self::assertFalse($price->isCurrent(new FixedVatRates([])));
    }

    private function stored(int $version): VariantPrice
    {
        return VariantPrice::reconstitute(
            VariantId::fromString(self::ID),
            PriceBreakdown::fromGross(GrossPrice::pln(10000), VatRate::fromBasisPoints(2300)),
            TaxClass::fromString('standard'),
            $version,
            new \DateTimeImmutable('2026-10-01 12:00:00+00:00'),
        );
    }
}
