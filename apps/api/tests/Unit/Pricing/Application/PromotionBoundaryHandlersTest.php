<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Command\PromotionBoundaryReached;
use App\Pricing\Application\Command\PromotionBoundaryReachedHandler;
use App\Pricing\Application\Command\PromotionPriceEvents;
use App\Pricing\Application\Command\RefreshPromotionProducts;
use App\Pricing\Application\Command\RefreshPromotionProductsHandler;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionPriceChangeSource;
use App\Pricing\Domain\Promotion\PromotionPricesChanged;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryPromotionRepository;
use App\Tests\Unit\Pricing\InMemoryVariantSummaries;
use App\Tests\Unit\Pricing\RecordingPromotionBoundaries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The handlers of PromotionBoundaryReached and RefreshPromotionProducts with in-memory adapters.
 */
final class PromotionBoundaryHandlersTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5c00';
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5d00';
    private const string OTHER_PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5d01';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5cff';
    private const string STARTS_AT = '2026-11-11T00:00:00Z';
    private const string ENDS_AT = '2026-11-21T00:00:00Z';

    private InMemoryPromotionRepository $promotions;
    private InMemoryVariantSummaries $catalog;
    private RecordingDomainEventPublisher $events;
    private RecordingPromotionBoundaries $boundaries;
    private FixedPricingClock $clock;

    protected function setUp(): void
    {
        $this->promotions = new InMemoryPromotionRepository();
        $this->catalog = new InMemoryVariantSummaries();
        $this->events = new RecordingDomainEventPublisher();
        $this->boundaries = new RecordingPromotionBoundaries();
        $this->clock = new FixedPricingClock('2026-11-11T00:00:00.300000Z');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function boundaries(): iterable
    {
        yield 'start' => [self::STARTS_AT, '2026-11-11T00:00:00.300000Z'];
        yield 'end' => [self::ENDS_AT, '2026-11-21T00:00:00Z'];
    }

    #[DataProvider('boundaries')]
    public function testAReachedBoundaryPublishesOneEventPerProductOfThePromotion(string $boundary, string $now): void
    {
        $variants = $this->variantsOf(self::PRODUCT, 20);
        $this->store($variants);
        $this->clock->now = new \DateTimeImmutable($now);

        $this->boundaryReached($boundary);

        self::assertEquals([new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Promotion, $this->clock->now)], $this->events->published);
        self::assertSame([$variants], $this->catalog->asked, 'Catalog is asked once for all the variants');
        self::assertSame([], $this->boundaries->redelivered);
    }

    public function testVariantsOfSeveralProductsGiveAnEventPerProductAndAVariantUnknownToCatalogIsSkipped(): void
    {
        [$first, $second] = $this->variantsOf(self::PRODUCT, 2);
        [$third] = $this->variantsOf(self::OTHER_PRODUCT, 1, offset: 50);
        $this->store([$first, self::UNKNOWN, $third, $second]);

        $this->boundaryReached(self::STARTS_AT);

        self::assertSame(
            [self::PRODUCT, self::OTHER_PRODUCT],
            array_map(static fn (object $event): string => $event instanceof PromotionPricesChanged ? $event->productId : '', $this->events->published),
        );
    }

    public function testAPromotionWhoseVariantsCatalogDoesNotKnowPublishesNothing(): void
    {
        $this->store([self::UNKNOWN]);

        $this->boundaryReached(self::STARTS_AT);

        self::assertSame([], $this->events->published);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staleMoments(): iterable
    {
        yield 'a start moved later' => ['2026-11-10T00:00:00Z'];
        yield 'an end moved' => ['2026-11-20T00:00:00Z'];
        yield 'a second off the start' => ['2026-11-11T00:00:01Z'];
    }

    #[DataProvider('staleMoments')]
    public function testAMessageOfAnotherMomentThanTheCurrentBoundariesHasNoEffect(string $at): void
    {
        $this->store($this->variantsOf(self::PRODUCT, 1));
        $this->clock->now = new \DateTimeImmutable('2026-11-21T00:00:00Z');

        $this->boundaryReached($at);

        self::assertSame([], $this->events->published);
        self::assertSame([], $this->catalog->asked);
        self::assertSame([], $this->boundaries->redelivered);
    }

    public function testAMessageOfAnUnknownPromotionHasNoEffect(): void
    {
        $this->boundaryReached(self::STARTS_AT);

        self::assertSame([], $this->events->published);
        self::assertSame([], $this->catalog->asked);
        self::assertSame([], $this->boundaries->redelivered);
    }

    public function testAMessageThatCameTooEarlyIsSentAgainForASecondAfterTheBoundary(): void
    {
        $this->store($this->variantsOf(self::PRODUCT, 1));
        $this->clock->now = new \DateTimeImmutable('2026-11-10T23:59:59.900000Z');

        $this->boundaryReached(self::STARTS_AT);

        self::assertSame([], $this->events->published);
        self::assertSame([], $this->catalog->asked);
        self::assertCount(1, $this->boundaries->redelivered);
        [$id, $boundary, $dueAt] = $this->boundaries->redelivered[0];
        self::assertSame(self::ID, $id);
        self::assertSame('2026-11-11T00:00:00+00:00', $boundary->format(\DATE_ATOM));
        self::assertSame('2026-11-11T00:00:01+00:00', $dueAt->format(\DATE_ATOM));
    }

    public function testARefreshOfAnActivePromotionPublishesOneEventPerProduct(): void
    {
        $this->store([...$this->variantsOf(self::PRODUCT, 3), ...$this->variantsOf(self::OTHER_PRODUCT, 2, offset: 50)]);
        $this->clock->now = new \DateTimeImmutable('2026-11-15T10:00:00Z');

        $this->refresh();

        self::assertEquals([
            new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Promotion, $this->clock->now),
            new PromotionPricesChanged(self::OTHER_PRODUCT, PromotionPriceChangeSource::Promotion, $this->clock->now),
        ], $this->events->published);
    }

    public function testARefreshOfAnEndedPromotionHasNoEffect(): void
    {
        $this->store($this->variantsOf(self::PRODUCT, 1));
        $this->clock->now = new \DateTimeImmutable(self::ENDS_AT);

        $this->refresh();

        self::assertSame([], $this->events->published);
        self::assertSame([], $this->catalog->asked);
    }

    public function testARefreshOfAnUnknownPromotionHasNoEffect(): void
    {
        $this->refresh();

        self::assertSame([], $this->events->published);
        self::assertSame([], $this->catalog->asked);
    }

    private function boundaryReached(string $at): void
    {
        $handler = new PromotionBoundaryReachedHandler($this->promotions, $this->priceEvents(), $this->boundaries, $this->clock);
        $handler(new PromotionBoundaryReached(self::ID, new \DateTimeImmutable($at)));
    }

    private function refresh(): void
    {
        $handler = new RefreshPromotionProductsHandler($this->promotions, $this->priceEvents(), $this->clock);
        $handler(new RefreshPromotionProducts(self::ID));
    }

    private function priceEvents(): PromotionPriceEvents
    {
        return new PromotionPriceEvents($this->catalog, $this->events);
    }

    /**
     * @return list<string> variant ids Catalog knows as variants of the product
     */
    private function variantsOf(string $productId, int $count, int $offset = 0): array
    {
        $ids = [];
        for ($i = $offset; $i < $offset + $count; ++$i) {
            $id = \sprintf('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c%04d', $i);
            $this->catalog->add($id, $productId, 'Produkt', 'Etykieta '.$i);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param list<string> $variants
     */
    private function store(array $variants): void
    {
        $this->promotions->store(Promotion::plan(
            PromotionId::fromString(self::ID), 'Jesień', PercentOff::of(20), new \DateTimeImmutable(self::STARTS_AT), new \DateTimeImmutable(self::ENDS_AT),
            array_map(VariantId::fromString(...), $variants), null, new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        ));
    }
}
