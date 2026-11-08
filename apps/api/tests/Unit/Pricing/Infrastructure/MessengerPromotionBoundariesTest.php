<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Infrastructure;

use App\Pricing\Application\Command\DeletePromotion;
use App\Pricing\Application\Command\DeletePromotionHandler;
use App\Pricing\Application\Command\PlanPromotion;
use App\Pricing\Application\Command\PlanPromotionHandler;
use App\Pricing\Application\Command\PromotionBoundaryReached;
use App\Pricing\Application\Command\PromotionVariantRules;
use App\Pricing\Application\Command\RefreshPromotionProducts;
use App\Pricing\Application\Command\RevisePromotion;
use App\Pricing\Application\Command\RevisePromotionHandler;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Infrastructure\Messaging\MessengerPromotionBoundaries;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryPromotionRepository;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use App\Tests\Unit\Pricing\InMemoryVariantSummaries;
use App\Tests\Unit\Pricing\RecordingPromotionWriteLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The messages the promotion writes send through MessengerPromotionBoundaries: the command handlers with in-memory
 * adapters and the real adapter on a recording command.bus. The time of Symfony's clock (the DelayStamp counts the
 * delay from it) is the time of the clock of Pricing.
 */
final class MessengerPromotionBoundariesTest extends TestCase
{
    use ClockSensitiveTrait;

    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c00';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c01';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c02';
    private const string NOW = '2026-11-10T12:00:00.400000Z';

    private InMemoryPromotionRepository $promotions;
    private InMemoryVariantSummaries $catalog;
    private RecordingMessageBus $bus;
    private PlanPromotionHandler $plan;
    private RevisePromotionHandler $revise;
    private DeletePromotionHandler $delete;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->promotions = new InMemoryPromotionRepository();
        $this->catalog = new InMemoryVariantSummaries();
        $prices = new InMemoryVariantPriceRepository();
        $this->bus = new RecordingMessageBus();
        $clock = new FixedPricingClock(self::NOW);
        $lock = new RecordingPromotionWriteLock();
        $boundaries = new MessengerPromotionBoundaries($this->bus);
        foreach ([self::A, self::B] as $i => $variant) {
            $this->catalog->add($variant, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5d0'.$i, 'Produkt', 'Etykieta');
            $prices->store(VariantPrice::reconstitute(
                VariantId::fromString($variant),
                PriceBreakdown::fromGross(GrossPrice::pln(10000), VatRate::fromBasisPoints(2300)),
                TaxClass::fromString('standard'),
                1,
                new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            ));
        }
        $rules = new PromotionVariantRules($this->catalog, $prices, new PolishVatRates());

        $this->plan = new PlanPromotionHandler($this->promotions, $rules, $lock, $clock, $boundaries);
        $this->revise = new RevisePromotionHandler($this->promotions, $rules, $lock, $clock, $boundaries);
        $this->delete = new DeletePromotionHandler($this->promotions, $lock, $clock);
    }

    public function testAPlannedPromotionSendsBothBoundariesDelayedUntilTheirMoments(): void
    {
        ($this->plan)(self::plan('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z'));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-11T00:00:00+00:00', self::secondsFromNow('2026-11-11T00:00:00Z') * 1000],
            [PromotionBoundaryReached::class, self::ID, '2026-11-21T00:00:00+00:00', self::secondsFromNow('2026-11-21T00:00:00Z') * 1000],
        ], $this->sent());
    }

    public function testAPromotionFromNowSendsItsStartForTheStartOfNow(): void
    {
        ($this->plan)(self::plan(null, '2026-11-21T00:00:00Z'));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-10T12:00:01+00:00', 1000],
            [PromotionBoundaryReached::class, self::ID, '2026-11-21T00:00:00+00:00', self::secondsFromNow('2026-11-21T00:00:00Z') * 1000],
        ], $this->sent());
    }

    public function testASeededPromotionSendsOnlyItsEnd(): void
    {
        ($this->plan)(PlanPromotion::bySeed(self::ID, 'Jesień', 20, new \DateTimeImmutable('2026-12-10T12:00:00Z'), [self::A, self::B]));

        self::assertSame('2026-11-10T12:00:01+00:00', $this->promotions->find(PromotionId::fromString(self::ID))?->period()->startsAt()->format(\DATE_ATOM), 'the seeded promotion starts at the start of now');
        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-12-10T12:00:00+00:00', self::secondsFromNow('2026-12-10T12:00:00Z') * 1000],
        ], $this->sent(), 'the start of the seed needs no event: make seed purges the store afterwards');
    }

    public function testTheStartIsLeftOutWhenTheAdapterIsToldNotToAnnounceIt(): void
    {
        $promotion = $this->promotion('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z');

        new MessengerPromotionBoundaries($this->bus)->promotionSaved($promotion, null, new \DateTimeImmutable(self::NOW), announceStart: false);

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-21T00:00:00+00:00', self::secondsFromNow('2026-11-21T00:00:00Z') * 1000],
        ], $this->sent());
    }

    public function testEndNowOfAnActivePromotionSendsTheBoundaryOfItsNewEndOnly(): void
    {
        $this->storeActive();

        ($this->revise)(self::revise(endsAt: '2026-11-10T12:00:00Z'));

        self::assertSame([[PromotionBoundaryReached::class, self::ID, '2026-11-10T12:00:01+00:00', 1000]], $this->sent());
    }

    public function testMovingTheEndOfAnActivePromotionSendsItsNewEndAndARefreshOfItsProductsAtOnce(): void
    {
        $this->storeActive();

        ($this->revise)(self::revise(endsAt: '2026-11-25T00:00:00Z'));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-25T00:00:00+00:00', self::secondsFromNow('2026-11-25T00:00:00Z') * 1000],
            [RefreshPromotionProducts::class, self::ID, null, null],
        ], $this->sent());
        self::assertSame([], $this->catalog->asked, 'the write does not ask Catalog for the products');
    }

    public function testMovingTheEndOfAnActivePromotionEarlierRefreshesItsProductsToo(): void
    {
        $this->storeActive();

        ($this->revise)(self::revise(endsAt: '2026-11-15T00:00:00Z'));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-15T00:00:00+00:00', self::secondsFromNow('2026-11-15T00:00:00Z') * 1000],
            [RefreshPromotionProducts::class, self::ID, null, null],
        ], $this->sent());
    }

    public function testRenamingAnActivePromotionSendsItsEndAgainWithoutARefresh(): void
    {
        $this->storeActive();

        ($this->revise)(self::revise(name: 'Nowa nazwa'));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-21T00:00:00+00:00', self::secondsFromNow('2026-11-21T00:00:00Z') * 1000],
        ], $this->sent());
    }

    public function testAPlannedPromotionSavedWithTheSameBoundariesSendsBothAgain(): void
    {
        $this->promotions->store($this->promotion('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z'));

        ($this->revise)(self::revise(name: 'Nowa nazwa', startsAt: '2026-11-11T00:00:00Z'));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-11T00:00:00+00:00', self::secondsFromNow('2026-11-11T00:00:00Z') * 1000],
            [PromotionBoundaryReached::class, self::ID, '2026-11-21T00:00:00+00:00', self::secondsFromNow('2026-11-21T00:00:00Z') * 1000],
        ], $this->sent());
    }

    public function testAPlannedPromotionWithAnotherStartSendsItsNewStartAndItsKeptEnd(): void
    {
        $this->promotions->store($this->promotion('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z'));

        ($this->revise)(self::revise(startsAt: '2026-11-12T00:00:00Z'));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-12T00:00:00+00:00', self::secondsFromNow('2026-11-12T00:00:00Z') * 1000],
            [PromotionBoundaryReached::class, self::ID, '2026-11-21T00:00:00+00:00', self::secondsFromNow('2026-11-21T00:00:00Z') * 1000],
        ], $this->sent());
    }

    public function testAPlannedPromotionStartedFromNowSendsItsStartForTheStartOfNowAndItsKeptEnd(): void
    {
        $this->promotions->store($this->promotion('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z'));

        ($this->revise)(self::revise(startsAt: null));

        self::assertSame([
            [PromotionBoundaryReached::class, self::ID, '2026-11-10T12:00:01+00:00', 1000],
            [PromotionBoundaryReached::class, self::ID, '2026-11-21T00:00:00+00:00', self::secondsFromNow('2026-11-21T00:00:00Z') * 1000],
        ], $this->sent());
    }

    public function testDeletingAPlannedPromotionSendsNothing(): void
    {
        $this->promotions->store($this->promotion('2026-11-11T00:00:00Z', '2026-11-21T00:00:00Z'));

        ($this->delete)(new DeletePromotion(self::ID, 1));

        self::assertSame([], $this->bus->dispatched);
    }

    public function testABoundaryDeliveredAgainKeepsItsMomentAndIsDelayedUntilTheMomentGiven(): void
    {
        new MessengerPromotionBoundaries($this->bus)->redeliverBoundary(
            PromotionId::fromString(self::ID),
            new \DateTimeImmutable('2026-11-10T12:00:01Z'),
            new \DateTimeImmutable('2026-11-10T12:00:02Z'),
        );

        self::assertSame([[PromotionBoundaryReached::class, self::ID, '2026-11-10T12:00:01+00:00', 2000]], $this->sent());
    }

    /**
     * The class, the promotion, the boundary and the delay in milliseconds (null without a DelayStamp) of every message.
     *
     * @return list<array{class-string, string, string|null, int|null}>
     */
    private function sent(): array
    {
        return array_map(static function (Envelope $envelope): array {
            $message = $envelope->getMessage();
            \assert($message instanceof PromotionBoundaryReached || $message instanceof RefreshPromotionProducts);

            return [
                $message::class,
                $message->promotionId,
                $message instanceof PromotionBoundaryReached ? $message->at->format(\DATE_ATOM) : null,
                $envelope->last(DelayStamp::class)?->getDelay(),
            ];
        }, $this->bus->envelopes);
    }

    private static function secondsFromNow(string $moment): int
    {
        return new \DateTimeImmutable($moment)->getTimestamp() - new \DateTimeImmutable(self::NOW)->getTimestamp();
    }

    private function storeActive(): void
    {
        $this->promotions->store($this->promotion('2026-11-01T00:00:00Z', '2026-11-21T00:00:00Z'));
    }

    private function promotion(string $startsAt, string $endsAt): Promotion
    {
        return Promotion::plan(
            PromotionId::fromString(self::ID), 'Jesień', PercentOff::of(20), new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt),
            [VariantId::fromString(self::A), VariantId::fromString(self::B)], null, new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }

    private static function plan(?string $startsAt, string $endsAt): PlanPromotion
    {
        return PlanPromotion::byStaff(self::ID, 'Jesień', 20, null === $startsAt ? null : new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt), [self::A, self::B], 'sub-1', 'Anna Marketing');
    }

    /**
     * A revise of the stored promotion (version 1) with the values it has unless given.
     */
    private static function revise(string $name = 'Jesień', ?string $startsAt = '2026-11-01T00:00:00Z', string $endsAt = '2026-11-21T00:00:00Z'): RevisePromotion
    {
        return new RevisePromotion(self::ID, 1, $name, 20, null === $startsAt ? null : new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt), [self::A, self::B], 'sub-2', 'Bartek');
    }
}
