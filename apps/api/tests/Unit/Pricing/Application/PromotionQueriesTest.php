<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Query\GetPromotion;
use App\Pricing\Application\Query\GetPromotionHandler;
use App\Pricing\Application\Query\InvalidPromotionCursor;
use App\Pricing\Application\Query\ListPromotions;
use App\Pricing\Application\Query\ListPromotionsHandler;
use App\Pricing\Application\Query\PromotionCursor;
use App\Pricing\Application\Query\PromotionVariantView;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionActor;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionNotFound;
use App\Pricing\Domain\Promotion\PromotionStatus;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Tests\Unit\Pricing\FixedPricingClock;
use App\Tests\Unit\Pricing\InMemoryPromotionReader;
use App\Tests\Unit\Pricing\InMemoryPromotionRepository;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use App\Tests\Unit\Pricing\InMemoryVariantSummaries;
use PHPUnit\Framework\TestCase;

/**
 * ListPromotions and GetPromotion with in-memory adapters.
 */
final class PromotionQueriesTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f00';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f01';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f02';
    private const string DELETED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f03';
    private const string OUTDATED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f04';
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f10';

    private FixedPricingClock $clock;
    private InMemoryPromotionReader $reader;
    private InMemoryPromotionRepository $promotions;
    private InMemoryVariantSummaries $catalog;
    private InMemoryVariantPriceRepository $prices;

    protected function setUp(): void
    {
        $this->clock = new FixedPricingClock('2026-11-10T12:00:00Z');
        $this->reader = new InMemoryPromotionReader();
        $this->promotions = new InMemoryPromotionRepository();
        $this->catalog = new InMemoryVariantSummaries();
        $this->prices = new InMemoryVariantPriceRepository();
    }

    public function testTheListGivesAPageWithTheStatusOfEachPromotionAndTheCursorAfterIt(): void
    {
        $this->reader->promotions = [
            $this->listed('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f21', '2026-11-20T00:00:00Z', '2026-11-30T00:00:00Z'),
            $this->listed('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f22', '2026-11-01T00:00:00Z', '2026-11-15T00:00:00Z'),
            $this->listed('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f23', '2026-10-01T00:00:00Z', '2026-11-10T12:00:00Z'),
        ];
        $handler = new ListPromotionsHandler($this->reader, $this->clock);

        $first = $handler(new ListPromotions(null, null, 2));
        self::assertSame(['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f21', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f22'], array_column($first->items, 'id'));
        self::assertSame([PromotionStatus::Planned, PromotionStatus::Active], array_column($first->items, 'status'));
        self::assertSame(3, $this->reader->pages[0][3], 'one more than the page tells whether there is a next one');
        self::assertNotNull($first->next);

        $second = $handler(new ListPromotions(null, $first->next, 2));
        self::assertSame(['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f23'], array_column($second->items, 'id'));
        self::assertSame([PromotionStatus::Ended], array_column($second->items, 'status'));
        self::assertNull($second->next);
    }

    public function testTheListFiltersByTheStatusAtNow(): void
    {
        new ListPromotionsHandler($this->reader, $this->clock)(new ListPromotions(PromotionStatus::Active, null, 25));

        self::assertSame(PromotionStatus::Active, $this->reader->pages[0][0]);
        self::assertEquals($this->clock->now, $this->reader->pages[0][1]);
    }

    public function testACursorNobodyHandedOutIsRefused(): void
    {
        $this->expectException(InvalidPromotionCursor::class);

        new ListPromotionsHandler($this->reader, $this->clock)(new ListPromotions(null, 'garbage', 25));
    }

    public function testThePageCursorDecodesToTheLastPromotionOfThePage(): void
    {
        $this->reader->promotions = [
            $this->listed('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f21', '2026-11-20T00:00:00Z', '2026-11-30T00:00:00Z'),
            $this->listed('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f22', '2026-11-01T00:00:00Z', '2026-11-15T00:00:00Z'),
        ];

        $page = new ListPromotionsHandler($this->reader, $this->clock)(new ListPromotions(null, null, 1));

        self::assertNotNull($page->next);
        $cursor = PromotionCursor::decode($page->next);
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5f21', $cursor->id);
        self::assertSame('2026-11-20T00:00:00+00:00', $cursor->startsAt->format(\DATE_ATOM));
    }

    public function testAPromotionShowsItsVariantsWithTheirCatalogAndPromotionalPrices(): void
    {
        $this->promotions->store($this->promotion('2026-11-01T00:00:00Z', '2026-11-20T00:00:00Z'));
        $this->catalog->add(self::A, self::PRODUCT, 'Namiot Tarvik', 'Zielony');
        $this->catalog->add(self::B, self::PRODUCT, 'Namiot Tarvik', '');
        $this->catalog->add(self::OUTDATED, self::PRODUCT, 'Namiot Tarvik', 'Stary');
        $this->price(self::A, 59_99, 2300);
        $this->price(self::B, 1, 2300);
        $this->price(self::DELETED, 10_000, 2300);
        $this->price(self::OUTDATED, 10_000, 2200);

        $details = $this->getPromotion(self::ID);

        self::assertSame(self::ID, $details->id);
        self::assertSame(['Jesień', 20, PromotionStatus::Active, 4, 1], [$details->name, $details->percentOff, $details->status, $details->variantCount, $details->version]);
        self::assertSame('2026-11-01T00:00:00+00:00', $details->startsAt->format(\DATE_ATOM));
        self::assertSame('Anna', $details->updatedByName);
        self::assertEquals(
            [
                new PromotionVariantView(self::A, self::PRODUCT, 'Namiot Tarvik', 'Zielony', 59_99, 48_00),
                new PromotionVariantView(self::B, self::PRODUCT, 'Namiot Tarvik', '', 1, 1),
                new PromotionVariantView(self::DELETED, null, null, null, 10_000, 8_000),
                new PromotionVariantView(self::OUTDATED, self::PRODUCT, 'Namiot Tarvik', 'Stary', null, null),
            ],
            $details->variants,
        );
    }

    public function testAnEndedPromotionHasNoPromotionalPrices(): void
    {
        $this->promotions->store($this->promotion('2026-11-01T00:00:00Z', '2026-11-10T12:00:00Z'));
        $this->catalog->add(self::A, self::PRODUCT, 'Namiot Tarvik', 'Zielony');
        $this->price(self::A, 59_99, 2300);

        $details = $this->getPromotion(self::ID);

        self::assertSame(PromotionStatus::Ended, $details->status);
        self::assertSame(59_99, $details->variants[0]->regularGross);
        self::assertSame([null, null, null, null], array_column($details->variants, 'promotionalGross'));
    }

    public function testAnUnknownPromotionOrAnIdThatIsNoUuidIsNotFound(): void
    {
        foreach ([self::A, 'promotion-1'] as $id) {
            try {
                $this->getPromotion($id);
                self::fail('The promotion must not be found.');
            } catch (PromotionNotFound) {
            }
        }
        $this->addToAssertionCount(1);
    }

    private function getPromotion(string $id): \App\Pricing\Application\Query\PromotionDetails
    {
        return new GetPromotionHandler($this->promotions, $this->catalog, $this->prices, new PolishVatRates(), $this->clock)(new GetPromotion($id));
    }

    private function listed(string $id, string $startsAt, string $endsAt): ListedPromotion
    {
        return new ListedPromotion($id, 'Promocja', 10, new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt), 2, 1);
    }

    private function promotion(string $startsAt, string $endsAt): Promotion
    {
        return Promotion::plan(
            PromotionId::fromString(self::ID), 'Jesień', PercentOff::of(20), new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt),
            array_map(VariantId::fromString(...), [self::A, self::B, self::DELETED, self::OUTDATED]), PromotionActor::staff('sub-1', 'Anna'), new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
    }

    private function price(string $variant, int $gross, int $basisPoints): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($variant),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString('standard'),
            1,
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        ));
    }
}
