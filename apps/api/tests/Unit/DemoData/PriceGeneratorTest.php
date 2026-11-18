<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Catalog\ProductSpec;
use App\DemoData\Catalog\ProductType;
use App\DemoData\Identity\StaffRoles;
use App\DemoData\Pricing\PriceGenerator;
use App\DemoData\Pricing\PriceTimeline;
use App\DemoData\Pricing\PromotionSpec;
use App\DemoData\Profile\Profiles;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class PriceGeneratorTest extends TestCase
{
    private static ?Dictionaries $dictionaries = null;
    /** @var list<ProductSpec>|null */
    private static ?array $products = null;
    /** @var array<string, list<PriceTimeline>>|null */
    private static ?array $timelines = null;

    public static function tearDownAfterClass(): void
    {
        self::$products = null;
        self::$timelines = null;
        self::$dictionaries = null;
    }

    private static function dictionaries(): Dictionaries
    {
        return self::$dictionaries ??= new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
    }

    private static function people(): PersonDictionaries
    {
        return new PersonDictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
    }

    private static function profiles(): Profiles
    {
        return Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
    }

    private static function anchor(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17 00:00:00', new \DateTimeZone('UTC'));
    }

    private static function generator(int $staff = 20): PriceGenerator
    {
        return new PriceGenerator(self::dictionaries(), self::profiles()->namespace, self::anchor(), $staff, self::people());
    }

    /**
     * @return list<ProductSpec> the 2000 products of the profile small
     */
    private static function products(): array
    {
        if (null === self::$products) {
            $generator = new ProductGenerator(self::dictionaries(), self::profiles()->namespace, self::anchor(), 2_000, self::profiles()->partSize);
            self::$products = [];
            for ($part = 0; $part < 4; ++$part) {
                foreach ($generator->part($part) as $spec) {
                    self::$products[] = $spec;
                }
            }
        }

        return self::$products;
    }

    /**
     * @return array<string, list<PriceTimeline>> by the id of the product
     */
    private static function timelines(): array
    {
        if (null === self::$timelines) {
            $generator = self::generator();
            self::$timelines = [];
            foreach (self::products() as $product) {
                self::$timelines[$product->id] = $generator->forProduct($product);
            }
        }

        return self::$timelines;
    }

    private static function typeOf(ProductSpec $product): ProductType
    {
        foreach (self::dictionaries()->types() as $type) {
            if ($type->key === $product->typeKey) {
                return $type;
            }
        }

        throw new \LogicException('Unknown type '.$product->typeKey);
    }

    public function testEveryVariantHasATimelineWithAnInitialEntryBeforeTheProductIsPublished(): void
    {
        foreach (self::products() as $product) {
            $timelines = self::timelines()[$product->id];

            self::assertCount(\count($product->variants), $timelines, $product->slug);
            foreach ($timelines as $timeline) {
                $first = $timeline->entries()[0];
                self::assertSame('seed', $first->source);
                self::assertNull($first->actorId);
                self::assertNull($first->actorName);
                $published = $product->firstPublishedAt;
                if (null !== $published) {
                    // The first order of a variant is placed after its product is published.
                    self::assertLessThan($published, $first->changedAt, $product->slug);
                }
            }
        }
    }

    public function testThePricesAreOnTheLadderOfTheTypeWithARetailEnding(): void
    {
        foreach (self::products() as $product) {
            $type = self::typeOf($product);
            foreach (self::timelines()[$product->id] as $timeline) {
                foreach ($timeline->entries() as $entry) {
                    self::assertGreaterThanOrEqual($type->priceMin * 100 - 1, $entry->gross, $product->slug);
                    self::assertLessThanOrEqual($type->priceMax * 100, $entry->gross, $product->slug);
                    self::assertContains($entry->gross % 100, [0, 99], $product->slug);
                }
            }
        }
    }

    public function testNetAndVatComeFromThePricingRuleAtTheRateOfTheTaxClass(): void
    {
        $rates = new PolishVatRates();
        $classes = [];
        foreach (self::products() as $product) {
            foreach (self::timelines()[$product->id] as $timeline) {
                foreach ($timeline->entries() as $entry) {
                    $expected = PriceBreakdown::fromGross(GrossPrice::pln($entry->gross), $rates->rateFor(TaxClass::fromString($product->taxClass)));
                    self::assertSame($expected->net()->amount(), $entry->net);
                    self::assertSame($expected->vat()->amount(), $entry->vat);
                    self::assertSame($expected->rate()->basisPoints(), $entry->rateBasisPoints);
                    self::assertSame($product->taxClass, $entry->taxClass);
                    self::assertSame($entry->gross, $entry->net + $entry->vat);
                    $classes[$product->taxClass] = true;
                }
            }
        }
        self::assertGreaterThan(1, \count($classes), 'The tail has more than one tax class.');
    }

    public function testVariantsOfAProductShareOnePriceUnlessTheySurchargeSizes(): void
    {
        $surcharged = 0;
        foreach (self::products() as $product) {
            $hasSize = \in_array('Rozmiar', $product->axes, true);
            $initial = array_map(static fn (PriceTimeline $timeline): int => $timeline->entries()[0]->gross, self::timelines()[$product->id]);
            if (!$hasSize || 1 === \count($initial)) {
                self::assertCount(1, array_unique($initial), $product->slug.' has no size to charge for.');
            } elseif (\count(array_unique($initial)) > 1) {
                ++$surcharged;
            }
        }
        self::assertGreaterThan(20, $surcharged, 'Some products charge more for bigger sizes.');
    }

    public function testAboutThirtyPercentOfTheVariantsHaveAStaffChangeInTheLastNinetyDays(): void
    {
        $variants = 0;
        $changed = 0;
        // The actor is a generated staff account with the role admin: its id (with the fingerprint) and its name.
        $accounts = new AccountGenerator(self::profiles()->namespace, self::people());
        $admins = [];
        foreach (StaffRoles::adminNumbers(20) as $number) {
            $admins[$accounts->staffId($number)] = $accounts->staff($number)->person->fullName();
        }
        $windowStart = self::anchor()->modify('-90 days');
        foreach (self::products() as $product) {
            foreach (self::timelines()[$product->id] as $timeline) {
                ++$variants;
                $entries = $timeline->entries();
                self::assertLessThanOrEqual(2, \count($entries));
                if (2 === \count($entries)) {
                    ++$changed;
                    $change = $entries[1];
                    self::assertSame('staff', $change->source);
                    self::assertArrayHasKey((string) $change->actorId, $admins);
                    self::assertSame($admins[(string) $change->actorId], $change->actorName);
                    self::assertGreaterThanOrEqual($windowStart, $change->changedAt);
                    self::assertLessThan(self::anchor(), $change->changedAt);
                    self::assertGreaterThan($entries[0]->changedAt, $change->changedAt);
                    self::assertNotSame($entries[0]->gross, $change->gross);
                    self::assertSame($change->changedAt->getTimestamp(), (int) $change->changedAt->format('U'), 'whole seconds');
                }
            }
        }

        self::assertEqualsWithDelta(0.30, $changed / $variants, 0.04);
    }

    public function testHistoryIdsAreUuidV7WithTheTimeOfTheEntry(): void
    {
        $seen = [];
        foreach (\array_slice(self::products(), 0, 300) as $product) {
            foreach (self::timelines()[$product->id] as $timeline) {
                foreach ($timeline->entries() as $entry) {
                    $uuid = Uuid::fromString($entry->id);
                    self::assertInstanceOf(UuidV7::class, $uuid);
                    self::assertSame((int) $entry->changedAt->format('Uv'), (int) $uuid->getDateTime()->format('Uv'));
                    self::assertArrayNotHasKey($entry->id, $seen);
                    $seen[$entry->id] = true;
                }
            }
        }
    }

    public function testTheSameProductGivesTheSameEntriesWhateverWasGeneratedBefore(): void
    {
        $product = self::products()[1_234];

        $alone = self::generator()->forProduct($product);
        $generator = self::generator();
        $generator->forProduct(self::products()[1]);
        $afterAnother = $generator->forProduct($product);

        self::assertEquals($alone, $afterAnother);
        self::assertEquals($alone, self::timelines()[$product->id]);
    }

    public function testADifferentSeedGivesDifferentPrices(): void
    {
        $other = new PriceGenerator(self::dictionaries(), 'b3a7d1f0-3c64-4e8b-9a52-1d7e0c5f6a49', self::anchor(), 20, self::people());

        $differs = false;
        foreach (\array_slice(self::products(), 0, 50) as $product) {
            $differs = $differs || $other->forProduct($product) != self::timelines()[$product->id];
        }

        self::assertTrue($differs);
    }

    public function testAChangeNeverFallsInsideAPromotionOfTheVariant(): void
    {
        // Every variant is in a promotion that covers the whole window of changes but its last day.
        $promotionStart = self::anchor()->modify('-91 days');
        $promotionEnd = self::anchor()->modify('-1 day');
        $changedAfter = 0;
        foreach (\array_slice(self::products(), 0, 400) as $product) {
            $promotions = [];
            foreach ($product->variants as $variant) {
                $promotions[$variant->id] = [new PromotionSpec('promotion-'.$variant->id, 'Promocja', PercentOff::of(20), PromotionPeriod::of($promotionStart, $promotionEnd), $promotionStart->modify('-3 days'), [$variant->id])];
            }

            foreach (self::generator()->forProduct($product, $promotions) as $timeline) {
                $entries = $timeline->entries();
                if (2 === \count($entries)) {
                    ++$changedAfter;
                    self::assertGreaterThanOrEqual($promotionEnd, $entries[1]->changedAt, 'A price is locked while its promotion runs.');
                }
            }
        }
        self::assertGreaterThan(0, $changedAfter);
    }

    public function testThePromotionsOfAVariantComeWithItsTimeline(): void
    {
        $product = self::products()[10];
        $variant = $product->variants[0];
        $promotion = new PromotionSpec('promotion-1', 'Promocja', PercentOff::of(20), PromotionPeriod::of(self::anchor()->modify('-200 days'), self::anchor()->modify('-190 days')), self::anchor()->modify('-203 days'), [$variant->id]);

        $timelines = self::generator()->forProduct($product, [$variant->id => [$promotion]]);

        self::assertSame([$promotion], $timelines[0]->promotions());
        self::assertSame([], \count($timelines) > 1 ? $timelines[1]->promotions() : []);
    }

    public function testWithoutAdminsThereAreNoChanges(): void
    {
        $generator = self::generator(0);

        foreach (\array_slice(self::products(), 0, 100) as $product) {
            foreach ($generator->forProduct($product) as $timeline) {
                self::assertCount(1, $timeline->entries());
            }
        }
    }
}
