<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Catalog\ProductSpec;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Pricing\PromotionPlanner;
use App\DemoData\Pricing\PromotionSpec;
use App\DemoData\Profile\Profiles;
use App\Pricing\Domain\Promotion\Promotion;
use PHPUnit\Framework\TestCase;

final class PromotionPlannerTest extends TestCase
{
    private static ?Dictionaries $dictionaries = null;
    /** @var list<PromotionSpec>|null */
    private static ?array $promotions = null;

    public static function tearDownAfterClass(): void
    {
        self::$promotions = null;
        self::$dictionaries = null;
    }

    private static function dictionaries(): Dictionaries
    {
        return self::$dictionaries ??= new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
    }

    private static function profiles(): Profiles
    {
        return Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
    }

    private static function anchor(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17 00:00:00', new \DateTimeZone('UTC'));
    }

    private static function planner(int $products = 2_000, ?string $namespace = null): PromotionPlanner
    {
        $profiles = self::profiles();
        $namespace ??= $profiles->namespace;

        return new PromotionPlanner(self::dictionaries(), new ProductGenerator(self::dictionaries(), $namespace, self::anchor(), $products, $profiles->partSize), $namespace, self::anchor(), $products, $profiles->partSize);
    }

    /**
     * @return list<PromotionSpec>
     */
    private static function promotions(): array
    {
        return self::$promotions ??= self::planner()->plan();
    }

    public function testThereAreAFewDozenPromotions(): void
    {
        self::assertGreaterThanOrEqual(30, \count(self::promotions()));
        self::assertLessThanOrEqual(PromotionPlanner::COUNT, \count(self::promotions()));
    }

    public function testTheLimitOfVariantsIsThatOfThePromotionAggregate(): void
    {
        self::assertSame(Promotion::MAX_VARIANTS, new \ReflectionClassConstant(PromotionPlanner::class, 'MAX_VARIANTS')->getValue());
    }

    public function testEveryPromotionHasAtMostAThousandDistinctVariantsAndAtLeastOne(): void
    {
        foreach (self::promotions() as $promotion) {
            self::assertGreaterThanOrEqual(Promotion::MIN_VARIANTS, \count($promotion->variantIds), $promotion->name);
            self::assertLessThanOrEqual(Promotion::MAX_VARIANTS, \count($promotion->variantIds), $promotion->name);
            self::assertCount(\count($promotion->variantIds), array_unique($promotion->variantIds), $promotion->name);
        }
        $sizes = array_map(static fn (PromotionSpec $promotion): int => \count($promotion->variantIds), self::promotions());
        self::assertNotEmpty(array_filter($sizes, static fn (int $size): bool => $size > 100), 'Some promotions are big.');
        self::assertNotEmpty(array_filter($sizes, static fn (int $size): bool => $size < 100), 'Some promotions are small.');
    }

    public function testEveryPromotionHasEndedBeforeTheAnchorAndStartedInTheThreeYearsOfOrders(): void
    {
        foreach (self::promotions() as $promotion) {
            self::assertLessThanOrEqual(self::anchor(), $promotion->period->endsAt(), $promotion->name);
            self::assertGreaterThanOrEqual(self::anchor()->modify('-1095 days'), $promotion->period->startsAt(), $promotion->name);
            self::assertLessThan($promotion->period->startsAt(), $promotion->createdAt, $promotion->name);
        }
    }

    public function testSomePromotionsAreRecent(): void
    {
        $recent = array_filter(self::promotions(), static fn (PromotionSpec $promotion): bool => $promotion->period->endsAt() > self::anchor()->modify('-100 days'));

        self::assertGreaterThanOrEqual(4, \count($recent));
    }

    public function testNoVariantIsInTwoPromotionsWithOverlappingPeriods(): void
    {
        $byVariant = [];
        foreach (self::promotions() as $promotion) {
            foreach ($promotion->variantIds as $variantId) {
                foreach ($byVariant[$variantId] ?? [] as $other) {
                    self::assertFalse($promotion->period->overlaps($other->period), $variantId.' is in '.$promotion->name.' and '.$other->name);
                }
                $byVariant[$variantId][] = $promotion;
            }
        }
        self::assertNotEmpty(array_filter($byVariant, static fn (array $promotions): bool => \count($promotions) > 1), 'Some variants are in two promotions, one after the other.');
    }

    public function testThePromotedVariantsBelongToProductsPublishedBeforeThePromotionStarts(): void
    {
        $generator = new ProductGenerator(self::dictionaries(), self::profiles()->namespace, self::anchor(), 2_000, self::profiles()->partSize);
        /** @var array<string, ProductSpec> $owners */
        $owners = [];
        for ($part = 0; $part < 4; ++$part) {
            foreach ($generator->part($part) as $product) {
                foreach ($product->variants as $variant) {
                    $owners[$variant->id] = $product;
                }
            }
        }

        foreach (self::promotions() as $promotion) {
            foreach ($promotion->variantIds as $variantId) {
                self::assertArrayHasKey($variantId, $owners);
                $published = $owners[$variantId]->firstPublishedAt;
                self::assertNotNull($published, 'A draft is never promoted.');
                self::assertLessThanOrEqual($promotion->period->startsAt()->modify('-2 days'), $published);
            }
        }
    }

    public function testThePromotionsAreTheSameForTheSameInputsAndDifferentForAnotherSeed(): void
    {
        self::assertEquals(self::promotions(), self::planner()->plan());
        self::assertNotEquals(
            array_map(static fn (PromotionSpec $promotion): array => $promotion->variantIds, self::promotions()),
            array_map(static fn (PromotionSpec $promotion): array => $promotion->variantIds, self::planner(2_000, 'b3a7d1f0-3c64-4e8b-9a52-1d7e0c5f6a49')->plan()),
        );
    }

    public function testThePromotionIdIsTheUuidV5OfItsIndexSoItSurvivesSkippedPromotions(): void
    {
        $ids = array_map(static fn (PromotionSpec $promotion): string => $promotion->id, self::promotions());
        $expected = array_map(static fn (int $index): string => DemoUuid::v5(self::profiles()->namespace, 'promotion:'.$index), range(0, PromotionPlanner::COUNT - 1));

        self::assertSame([], array_diff($ids, $expected));
        self::assertCount(\count($ids), array_unique($ids));
    }

    public function testAVerySmallCatalogStillGivesValidPromotions(): void
    {
        $promotions = self::planner(30)->plan();

        self::assertLessThanOrEqual(PromotionPlanner::COUNT, \count($promotions));
        foreach ($promotions as $promotion) {
            self::assertNotSame([], $promotion->variantIds);
        }
    }

    public function testThePromotionsOfAVariantAreFoundById(): void
    {
        $promotion = self::promotions()[3];
        $variantId = $promotion->variantIds[0];

        $byVariant = PromotionPlanner::byVariant(self::promotions());

        self::assertContains($promotion, $byVariant[$variantId]);
        foreach ($byVariant[$variantId] as $item) {
            self::assertContains($variantId, $item->variantIds);
        }
    }

    public function testThePromotionsOfAVariantAreInTheOrderOfTheirStart(): void
    {
        foreach (PromotionPlanner::byVariant(self::promotions()) as $promotions) {
            $starts = array_map(static fn (PromotionSpec $promotion): int => $promotion->period->startsAt()->getTimestamp(), $promotions);
            $sorted = $starts;
            sort($sorted);
            self::assertSame($sorted, $starts);
        }
    }
}
