<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Command\SetVariantPrice;
use App\Pricing\Application\Command\SetVariantPriceHandler;
use App\Pricing\Application\Query\ListedPrice;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\GrossPriceOutOfRange;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantNotFound;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PriceLockedByPromotion;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRate;
use App\Shared\Domain\Exception\NotFound;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryPromotionRepository;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use App\Tests\Unit\Pricing\InMemoryVariantTaxClasses;
use App\Tests\Unit\Pricing\PricingJournal;
use App\Tests\Unit\Pricing\RecordingPromotionWriteLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SetVariantPriceHandlerTest extends TestCase
{
    private const string VARIANT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    private InMemoryVariantPriceRepository $prices;
    private InMemoryVariantTaxClasses $taxClasses;
    private InMemoryPromotionRepository $promotions;
    private PricingJournal $journal;
    private FixedPricingClock $clock;
    private SetVariantPriceHandler $handler;

    protected function setUp(): void
    {
        $this->prices = new InMemoryVariantPriceRepository();
        $this->taxClasses = new InMemoryVariantTaxClasses([self::VARIANT => 'standard']);
        $this->promotions = new InMemoryPromotionRepository();
        $this->journal = new PricingJournal();
        $this->clock = new FixedPricingClock('2026-11-10T12:00:00.400000Z', $this->journal);
        $this->handler = new SetVariantPriceHandler($this->prices, $this->taxClasses, new PolishVatRates(), $this->promotions, new RecordingPromotionWriteLock($this->journal), $this->clock);
    }

    public function testTheChangeOfAPriceInAnActivePromotionIsRefusedBeforeTheVersionIsChecked(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);
        $this->promotion('2026-11-01T00:00:00Z', '2026-11-20T00:00:00Z');

        foreach ([2, 1, null] as $version) {
            try {
                ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, $version, 'sub-1', 'Anna Admin'));
                self::fail('The price is locked by the promotion.');
            } catch (PriceLockedByPromotion) {
            }
        }

        self::assertSame(10000, $this->price()->breakdown()->gross()->amount());
        self::assertSame(0, $this->prices->writes);
    }

    public function testThePriceIsLockedFromTheStartOfThePromotionUntilBeforeItsEnd(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);
        $this->promotion('2026-11-10T12:00:00Z', '2026-11-10T12:00:01Z');

        $this->expectException(PriceLockedByPromotion::class);

        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, 2, 'sub-1', 'Anna Admin'));
    }

    public function testThePriceOfAVariantInAPlannedPromotionChanges(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);
        $this->promotion('2026-11-10T12:00:01Z', '2026-11-20T00:00:00Z');

        $saved = ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(12300, $saved->gross);
    }

    public function testThePriceOfAVariantInAnEndedPromotionChanges(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);
        $this->promotion('2026-11-01T00:00:00Z', '2026-11-10T12:00:00Z');

        $saved = ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(12300, $saved->gross);
    }

    public function testTheChangeTakesTheSharedLockBeforeTheClockAndIsWrittenAtThatMoment(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(['lock shared', 'clock'], $this->journal->entries);
        self::assertEquals($this->clock->now, $this->prices->history[0]->changedAt());
        self::assertEquals($this->clock->now, $this->price()->updatedAt());
    }

    public function testAFirstPriceIsWrittenAtTheMomentReadAfterTheLock(): void
    {
        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, null, 'sub-1', 'Anna Admin'));

        self::assertSame(['lock shared', 'clock'], $this->journal->entries);
        self::assertEquals($this->clock->now, $this->prices->history[0]->changedAt());
    }

    public function testFirstPriceIsCreatedAtTheRateOfTheClassOfTheVariantsProduct(): void
    {
        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, null, 'sub-1', 'Anna Admin'));

        $price = $this->price();
        self::assertSame(1, $price->version());
        self::assertSame('standard', $price->taxClass()->toString());
        self::assertSame(10000, $price->breakdown()->net()->amount());
        self::assertSame(2300, $price->breakdown()->vat()->amount());
        self::assertCount(1, $this->prices->history);
        $origin = $this->prices->history[0]->origin();
        self::assertSame(PriceChangeSource::Staff, $origin->source());
        self::assertSame('sub-1', $origin->actorId());
        self::assertSame('Anna Admin', $origin->actorName());
    }

    public function testReturnsTheSavedFirstPrice(): void
    {
        $saved = ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, null, 'sub-1', 'Anna Admin'));

        self::assertEquals(
            new ListedPrice(12300, 10000, 2300, 2300, 'PLN', 1, $this->price()->updatedAt(), false),
            $saved,
        );
    }

    public function testReturnsTheChangedPriceWithItsNewVersion(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        $saved = ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(3, $saved->version);
        self::assertSame([12300, 10000, 2300, 2300], [$saved->gross, $saved->net, $saved->vat, $saved->vatRate]);
        self::assertEquals($this->price()->updatedAt(), $saved->updatedAt);
        self::assertFalse($saved->vatRateOutdated);
    }

    public function testSameAmountReturnsTheStoredPriceUnchanged(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        $saved = ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertEquals(
            new ListedPrice(10000, 8130, 1870, 2300, 'PLN', 2, new \DateTimeImmutable('2026-10-01 12:00:00+00:00'), false),
            $saved,
        );
    }

    public function testFixingAnOutdatedRateReturnsTheCurrentRate(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 800, version: 2);

        $saved = ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(2300, $saved->vatRate);
        self::assertSame(3, $saved->version);
        self::assertFalse($saved->vatRateOutdated);
    }

    public function testSeedPriceHasNoActor(): void
    {
        ($this->handler)(SetVariantPrice::bySeed(self::VARIANT, 5490));

        self::assertCount(1, $this->prices->history);
        $origin = $this->prices->history[0]->origin();
        self::assertSame(PriceChangeSource::Seed, $origin->source());
        self::assertNull($origin->actorId());
        self::assertNull($origin->actorName());
    }

    public function testUnknownVariantIsNotFoundAndNothingIsStored(): void
    {
        try {
            ($this->handler)(SetVariantPrice::byStaff(self::UNKNOWN, 100, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected VariantNotFound.');
        } catch (VariantNotFound $e) {
            self::assertContains(NotFound::class, class_implements($e));
            self::assertSame('Wariant produktu nie istnieje.', $e->getMessage());
        }

        self::assertNull($this->prices->find(VariantId::fromString(self::UNKNOWN)));
        self::assertSame(0, $this->prices->writes);
    }

    public function testExpectedVersionForAVariantWithoutPriceIsAConflict(): void
    {
        try {
            ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 100, 1, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        self::assertNull($this->prices->find(VariantId::fromString(self::VARIANT)));
        self::assertSame(0, $this->prices->writes);
    }

    #[DataProvider('wrongVersions')]
    public function testWrongOrMissingVersionForAnExistingPriceIsAConflictAndChangesNothing(?int $expectedVersion): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        try {
            ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 20000, $expectedVersion, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        self::assertSame(2, $this->price()->version());
        self::assertSame(10000, $this->price()->breakdown()->gross()->amount());
        self::assertSame(0, $this->prices->writes);
    }

    /** @return iterable<string, array{int|null}> */
    public static function wrongVersions(): iterable
    {
        yield 'null' => [null];
        yield 'older' => [1];
        yield 'newer' => [3];
    }

    public function testSameAmountRecordsNothingAndKeepsTheVersion(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(2, $this->price()->version());
        self::assertSame([], $this->prices->history);
        self::assertSame(0, $this->prices->writes);
    }

    public function testNewAmountRecordsOneEntryAndANewVersion(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 2);

        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 12300, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(3, $this->price()->version());
        self::assertSame(12300, $this->price()->breakdown()->gross()->amount());
        self::assertCount(1, $this->prices->history);
        self::assertSame(12300, $this->prices->history[0]->breakdown()->gross()->amount());
    }

    public function testSameAmountFixesAnOutdatedRateWithOneEntry(): void
    {
        // Stored at 8% while the rate of `standard` is 23% today.
        $this->storePrice(gross: 10000, basisPoints: 800, version: 2);

        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 10000, 2, 'sub-1', 'Anna Admin'));

        self::assertSame(3, $this->price()->version());
        self::assertSame(2300, $this->price()->breakdown()->rate()->basisPoints());
        self::assertCount(1, $this->prices->history);
    }

    public function testExistingPriceKeepsItsOwnClassEvenWhenCatalogNowSaysOtherwise(): void
    {
        $this->storePrice(gross: 10000, basisPoints: 2300, version: 1);
        $handler = new SetVariantPriceHandler($this->prices, new InMemoryVariantTaxClasses([self::VARIANT => 'printed_book']), new PolishVatRates(), $this->promotions, new RecordingPromotionWriteLock(), $this->clock);

        $handler(SetVariantPrice::byStaff(self::VARIANT, 12300, 1, 'sub-1', 'Anna Admin'));

        self::assertSame('standard', $this->price()->taxClass()->toString());
        self::assertSame(2300, $this->price()->breakdown()->rate()->basisPoints());
    }

    public function testGrossOutOfRangeIsRejectedBeforeAnythingIsRead(): void
    {
        try {
            ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 0, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected GrossPriceOutOfRange.');
        } catch (GrossPriceOutOfRange) {
        }

        self::assertSame([], $this->taxClasses->asked);
        self::assertSame(0, $this->prices->writes);
    }

    public function testClassWithoutRateIsRejected(): void
    {
        $handler = new SetVariantPriceHandler($this->prices, new InMemoryVariantTaxClasses([self::VARIANT => 'luxury']), new PolishVatRates(), $this->promotions, new RecordingPromotionWriteLock(), $this->clock);

        $this->expectException(TaxClassWithoutRate::class);

        $handler(SetVariantPrice::byStaff(self::VARIANT, 100, null, 'sub-1', 'Anna Admin'));
    }

    public function testStaffChangeWithoutActorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ($this->handler)(SetVariantPrice::byStaff(self::VARIANT, 100, null, '', ''));
    }

    private function storePrice(int $gross, int $basisPoints, int $version): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString(self::VARIANT),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString('standard'),
            $version,
            new \DateTimeImmutable('2026-10-01 12:00:00+00:00'),
        ));
    }

    private function promotion(string $startsAt, string $endsAt): void
    {
        $this->promotions->store(Promotion::plan(
            PromotionId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5e00'), 'Jesień', PercentOff::of(20), new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt),
            [VariantId::fromString(self::VARIANT)], null, new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        ));
    }

    private function price(): VariantPrice
    {
        return $this->prices->find(VariantId::fromString(self::VARIANT)) ?? throw new \LogicException('No price stored.');
    }
}
