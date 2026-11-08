<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Command\DeletePromotion;
use App\Pricing\Application\Command\DeletePromotionHandler;
use App\Pricing\Application\Command\InvalidPromotionVariants;
use App\Pricing\Application\Command\PlanPromotion;
use App\Pricing\Application\Command\PlanPromotionHandler;
use App\Pricing\Application\Command\PromotionVariantRules;
use App\Pricing\Application\Command\RevisePromotion;
use App\Pricing\Application\Command\RevisePromotionHandler;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\InvalidPromotion;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Domain\Promotion\PromotionNotEditable;
use App\Pricing\Domain\Promotion\PromotionNotFound;
use App\Pricing\Domain\Promotion\PromotionOverlap;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryPromotionRepository;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use App\Tests\Unit\Pricing\InMemoryVariantSummaries;
use App\Tests\Unit\Pricing\PricingJournal;
use App\Tests\Unit\Pricing\RecordingPromotionBoundaries;
use App\Tests\Unit\Pricing\RecordingPromotionWriteLock;
use PHPUnit\Framework\TestCase;

/**
 * PlanPromotion, RevisePromotion and DeletePromotion with in-memory adapters.
 */
final class PromotionCommandsTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c00';
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c99';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c01';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c02';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c03';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c04';
    private const string UNPRICED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c05';
    private const string OUTDATED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c06';
    private const string NOW = '2026-11-10T12:00:00.400000Z';

    private PricingJournal $journal;
    private InMemoryPromotionRepository $promotions;
    private InMemoryVariantPriceRepository $prices;
    private InMemoryVariantSummaries $catalog;
    private RecordingPromotionBoundaries $boundaries;
    private FixedPricingClock $clock;
    private PlanPromotionHandler $plan;
    private RevisePromotionHandler $revise;
    private DeletePromotionHandler $delete;

    protected function setUp(): void
    {
        $this->journal = new PricingJournal();
        $this->promotions = new InMemoryPromotionRepository();
        $this->prices = new InMemoryVariantPriceRepository();
        $this->catalog = new InMemoryVariantSummaries();
        $this->boundaries = new RecordingPromotionBoundaries();
        $this->clock = new FixedPricingClock(self::NOW, $this->journal);
        $lock = new RecordingPromotionWriteLock($this->journal);
        $rules = new PromotionVariantRules($this->catalog, $this->prices, new PolishVatRates());

        foreach ([self::A, self::B, self::C, self::UNPRICED, self::OUTDATED] as $i => $variant) {
            $this->catalog->add($variant, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5d0'.$i, 'Produkt '.$i, 'Etykieta '.$i);
        }
        foreach ([self::A, self::B, self::C] as $variant) {
            $this->price($variant, 2300);
        }
        $this->price(self::OUTDATED, 2200);

        $this->plan = new PlanPromotionHandler($this->promotions, $rules, $lock, $this->clock, $this->boundaries);
        $this->revise = new RevisePromotionHandler($this->promotions, $rules, $lock, $this->clock, $this->boundaries);
        $this->delete = new DeletePromotionHandler($this->promotions, $lock, $this->clock);
    }

    public function testPlanSavesTheNewPromotionOfTheStaffMember(): void
    {
        ($this->plan)(self::planCommand(startsAt: '2026-11-11T00:00:00Z'));

        $promotion = $this->stored(self::ID);
        self::assertSame('Jesień', $promotion->name());
        self::assertSame(20, $promotion->percentOff()->value());
        self::assertSame('2026-11-11T00:00:00+00:00', $promotion->period()->startsAt()->format(\DATE_ATOM));
        self::assertSame([self::A, self::B], self::ids($promotion->variantIds()));
        self::assertSame(['sub-1', 'Anna Marketing'], [$promotion->createdBy()?->id(), $promotion->createdBy()?->name()]);
        self::assertEquals($this->clock->now, $promotion->createdAt());
    }

    public function testPlanTakesTheExclusiveLockBeforeItReadsTheClock(): void
    {
        ($this->plan)(self::planCommand());

        self::assertSame(['lock exclusive', 'clock'], $this->journal->entries);
    }

    public function testAPromotionFromNowStartsAtTheStartOfNow(): void
    {
        ($this->plan)(self::planCommand(startsAt: null));

        self::assertSame('2026-11-10T12:00:01+00:00', $this->stored(self::ID)->period()->startsAt()->format(\DATE_ATOM));
    }

    public function testThePlannedPromotionSchedulesItsBoundaries(): void
    {
        ($this->plan)(self::planCommand());

        self::assertCount(1, $this->boundaries->saved);
        [$id, $period, $previous, $now] = $this->boundaries->saved[0];
        self::assertSame(self::ID, $id);
        self::assertEquals($this->stored(self::ID)->period(), $period);
        self::assertNull($previous);
        self::assertEquals($this->clock->now, $now);
    }

    public function testTheStartIsAnnouncedForTheStaffAndNotForTheSeed(): void
    {
        ($this->plan)(self::planCommand());
        ($this->plan)(PlanPromotion::bySeed(self::OTHER, 'Demo', 10, new \DateTimeImmutable('2026-12-10T12:00:00Z'), [self::C]));
        ($this->revise)(new RevisePromotion(self::ID, 1, 'Jesień', 20, new \DateTimeImmutable('2026-11-12T00:00:00Z'), new \DateTimeImmutable('2026-11-21T00:00:00Z'), [self::A, self::B], 'sub-2', 'Bartek'));

        self::assertSame([true, false, true], $this->boundaries->announcedStart);
    }

    public function testAVariantUnknownToCatalogOrWithoutACurrentPriceIsRefused(): void
    {
        try {
            ($this->plan)(self::planCommand(variantIds: [self::A, self::UNKNOWN, self::UNPRICED, self::OUTDATED]));
            self::fail('The variants must be refused.');
        } catch (InvalidPromotionVariants $refused) {
            self::assertSame([self::UNKNOWN], $refused->unknown);
            self::assertSame([self::UNPRICED, self::OUTDATED], $refused->withoutPrice);
            self::assertSame('Wariant produktu nie istnieje.', $refused->getMessage());
        }

        self::assertSame(0, $this->promotions->writes);
        self::assertSame([], $this->boundaries->saved);
    }

    public function testAVariantWithoutPriceHasItsOwnMessage(): void
    {
        $this->expectExceptionObject(new InvalidPromotionVariants([], [self::UNPRICED]));
        self::assertSame('Wariant bez ceny nie może być w promocji.', new InvalidPromotionVariants([], [self::UNPRICED])->getMessage());

        ($this->plan)(self::planCommand(variantIds: [self::UNPRICED]));
    }

    public function testAnInvalidPromotionIsRefusedBeforeCatalogIsAsked(): void
    {
        try {
            ($this->plan)(self::planCommand(endsAt: '2026-11-10T12:00:00Z', variantIds: [self::UNKNOWN]));
            self::fail('The end in the past must be refused.');
        } catch (InvalidPromotion $invalid) {
            self::assertSame(InvalidPromotion::ENDS_AT, $invalid->field);
        }

        self::assertSame([], $this->catalog->asked);
        self::assertSame(0, $this->promotions->writes);
    }

    public function testAPromotionOverlappingAnotherOnACommonVariantIsRefusedWithTheConflictingVariants(): void
    {
        $this->promotions->store($this->promotion(self::OTHER, '2026-11-20T00:00:00Z', '2026-11-30T00:00:00Z', [self::B, self::C]));

        try {
            ($this->plan)(self::planCommand(startsAt: '2026-11-11T00:00:00Z', endsAt: '2026-11-21T00:00:00Z', variantIds: [self::C, self::A, self::B]));
            self::fail('The overlap must be refused.');
        } catch (PromotionOverlap $overlap) {
            self::assertSame([self::B, self::C], $overlap->conflictingVariantIds);
        }

        self::assertNull($this->promotions->find(PromotionId::fromString(self::ID)));
        self::assertSame([], $this->boundaries->saved);
    }

    public function testAPromotionRightAfterAnotherOnTheSameVariantsIsPlanned(): void
    {
        $this->promotions->store($this->promotion(self::OTHER, '2026-11-11T00:00:00Z', '2026-11-20T00:00:00Z', [self::A, self::B]));

        ($this->plan)(self::planCommand(startsAt: '2026-11-20T00:00:00Z', endsAt: '2026-11-30T00:00:00Z'));

        self::assertNotNull($this->promotions->find(PromotionId::fromString(self::ID)));
    }

    public function testReviseOfAnUnknownPromotionIsNotFound(): void
    {
        $this->expectException(PromotionNotFound::class);

        ($this->revise)(self::reviseCommand(1));
    }

    public function testReviseOfAnIdThatIsNotAUuidIsNotFound(): void
    {
        $this->expectException(PromotionNotFound::class);

        ($this->revise)(new RevisePromotion('promotion-1', 1, 'X', 20, null, new \DateTimeImmutable('2026-11-21T00:00:00Z'), [self::A], 'sub-1', 'Anna'));
    }

    public function testReviseOfAnotherVersionIsAConflictBeforeAnyRule(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-01T00:00:00Z', '2026-11-05T00:00:00Z', [self::A]));

        $this->expectException(PromotionModifiedConcurrently::class);

        ($this->revise)(self::reviseCommand(2));
    }

    public function testReviseOfAPlannedPromotionChangesItAndChecksOnlyTheAddedVariants(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z', [self::A, self::B]));
        // A variant of the promotion that has been deleted from the catalog since does not block the change.
        $this->catalog = new InMemoryVariantSummaries();
        $this->catalog->add(self::C, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5d03', 'Produkt', '');
        $this->revise = new RevisePromotionHandler($this->promotions, new PromotionVariantRules($this->catalog, $this->prices, new PolishVatRates()), new RecordingPromotionWriteLock($this->journal), $this->clock, $this->boundaries);

        ($this->revise)(self::reviseCommand(1, name: 'Zima', percentOff: 30, startsAt: '2026-11-12T00:00:00Z', endsAt: '2026-11-22T00:00:00Z', variantIds: [self::A, self::B, self::C]));

        $promotion = $this->stored(self::ID);
        self::assertSame(['Zima', 30, 2], [$promotion->name(), $promotion->percentOff()->value(), $promotion->version()]);
        self::assertSame([self::A, self::B, self::C], self::ids($promotion->variantIds()));
        self::assertSame(['sub-2', 'Bartek'], [$promotion->updatedBy()?->id(), $promotion->updatedBy()?->name()]);
        self::assertSame([[self::C]], $this->catalog->asked);
        self::assertSame(['lock exclusive', 'clock'], $this->journal->entries);
    }

    public function testAnAddedVariantWithoutPriceIsRefused(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z', [self::A]));

        try {
            ($this->revise)(self::reviseCommand(1, variantIds: [self::A, self::UNPRICED]));
            self::fail('The variant must be refused.');
        } catch (InvalidPromotionVariants $refused) {
            self::assertSame([self::UNPRICED], $refused->withoutPrice);
        }

        self::assertSame(1, $this->stored(self::ID)->version());
    }

    public function testReviseOfAnActivePromotionMovesItsEndWithoutAskingCatalog(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-01T00:00:00Z', '2026-11-21T00:00:00Z', [self::A, self::B]));

        ($this->revise)(self::reviseCommand(1, name: 'Dłuższa', startsAt: '2026-11-01T00:00:00Z', endsAt: '2026-11-25T00:00:00Z'));

        $promotion = $this->stored(self::ID);
        self::assertSame('2026-11-25T00:00:00+00:00', $promotion->period()->endsAt()->format(\DATE_ATOM));
        self::assertSame([], $this->catalog->asked);
        self::assertCount(1, $this->boundaries->saved);
        [, $period, $previous] = $this->boundaries->saved[0];
        self::assertSame('2026-11-25T00:00:00+00:00', $period->endsAt()->format(\DATE_ATOM));
        self::assertNotNull($previous);
        self::assertSame('2026-11-21T00:00:00+00:00', $previous->endsAt()->format(\DATE_ATOM));
    }

    public function testEndNowOfAnActivePromotionEndsItAtTheStartOfNow(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-01T00:00:00Z', '2026-11-21T00:00:00Z', [self::A, self::B]));

        ($this->revise)(self::reviseCommand(1, startsAt: '2026-11-01T00:00:00Z', endsAt: '2026-11-10T12:00:00Z'));

        self::assertSame('2026-11-10T12:00:01+00:00', $this->stored(self::ID)->period()->endsAt()->format(\DATE_ATOM));
    }

    public function testAnActivePromotionRefusesAnotherPercent(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-01T00:00:00Z', '2026-11-21T00:00:00Z', [self::A, self::B]));

        $this->expectExceptionObject(PromotionNotEditable::afterStart());

        ($this->revise)(self::reviseCommand(1, percentOff: 25, startsAt: '2026-11-01T00:00:00Z'));
    }

    public function testAMovedEndOverlappingAnotherPromotionIsRefused(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-01T00:00:00Z', '2026-11-21T00:00:00Z', [self::A, self::B]));
        $this->promotions->store($this->promotion(self::OTHER, '2026-11-21T00:00:00Z', '2026-11-30T00:00:00Z', [self::B]));

        try {
            ($this->revise)(self::reviseCommand(1, startsAt: '2026-11-01T00:00:00Z', endsAt: '2026-11-22T00:00:00Z'));
            self::fail('The overlap must be refused.');
        } catch (PromotionOverlap $overlap) {
            self::assertSame([self::B], $overlap->conflictingVariantIds);
        }

        self::assertSame(1, $this->stored(self::ID)->version());
        self::assertSame([], $this->boundaries->saved);
    }

    public function testDeleteRemovesAPlannedPromotionUnderTheExclusiveLock(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z', [self::A]));

        ($this->delete)(new DeletePromotion(self::ID, 1));

        self::assertNull($this->promotions->find(PromotionId::fromString(self::ID)));
        self::assertSame(['lock exclusive', 'clock'], $this->journal->entries);
    }

    public function testDeleteOfAnActivePromotionIsRefused(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-01T00:00:00Z', '2026-11-21T00:00:00Z', [self::A]));

        $this->expectExceptionObject(PromotionNotEditable::notDeletable());

        ($this->delete)(new DeletePromotion(self::ID, 1));
    }

    public function testDeleteOfAnotherVersionOrAnUnknownPromotionIsRefused(): void
    {
        $this->promotions->store($this->promotion(self::ID, '2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z', [self::A]));

        try {
            ($this->delete)(new DeletePromotion(self::ID, 2));
            self::fail('Another version must be refused.');
        } catch (PromotionModifiedConcurrently) {
        }
        $this->expectException(PromotionNotFound::class);
        ($this->delete)(new DeletePromotion(self::OTHER, 1));
    }

    /**
     * @param list<string> $variantIds
     */
    private static function planCommand(?string $startsAt = '2026-11-11T00:00:00Z', string $endsAt = '2026-11-21T00:00:00Z', array $variantIds = [self::A, self::B]): PlanPromotion
    {
        return PlanPromotion::byStaff(self::ID, 'Jesień', 20, null === $startsAt ? null : new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt), $variantIds, 'sub-1', 'Anna Marketing');
    }

    /**
     * @param list<string> $variantIds
     */
    private static function reviseCommand(int $version, string $name = 'Jesień', int $percentOff = 20, ?string $startsAt = '2026-11-11T00:00:00Z', string $endsAt = '2026-11-21T00:00:00Z', array $variantIds = [self::A, self::B]): RevisePromotion
    {
        return new RevisePromotion(self::ID, $version, $name, $percentOff, null === $startsAt ? null : new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt), $variantIds, 'sub-2', 'Bartek');
    }

    /**
     * A promotion planned long before now, so any period may be given.
     *
     * @param list<string> $variants
     */
    private function promotion(string $id, string $startsAt, string $endsAt, array $variants): Promotion
    {
        return Promotion::plan(
            PromotionId::fromString($id), 'Jesień', PercentOff::of(20), new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt),
            array_map(VariantId::fromString(...), $variants), null, new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }

    private function stored(string $id): Promotion
    {
        return $this->promotions->find(PromotionId::fromString($id)) ?? throw new \LogicException('No promotion stored.');
    }

    private function price(string $variant, int $basisPoints): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($variant),
            PriceBreakdown::fromGross(GrossPrice::pln(10000), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString('standard'),
            1,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        ));
    }

    /**
     * @param list<VariantId> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (VariantId $id): string => $id->toString(), $ids);
    }
}
