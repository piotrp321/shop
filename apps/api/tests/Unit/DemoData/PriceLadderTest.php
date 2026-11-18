<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Pricing\PriceLadder;
use PHPUnit\Framework\TestCase;

final class PriceLadderTest extends TestCase
{
    public function testTheRungsOfACheapRangeStepByFive(): void
    {
        self::assertSame([15, 20, 25, 30, 35, 40, 45], PriceLadder::rungs(15, 49));
    }

    public function testTheStepGrowsWithThePrice(): void
    {
        self::assertSame([20, 25, 30, 35, 40, 45, 50], PriceLadder::rungs(19, 59));
        self::assertSame([100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200], PriceLadder::rungs(100, 200));
        $expensive = PriceLadder::rungs(399, 2199);
        self::assertSame(400, $expensive[0]);
        self::assertSame(2100, $expensive[array_key_last($expensive)]);
        self::assertContains(1000, $expensive);
        self::assertContains(1100, $expensive);
    }

    public function testEveryRungIsInsideTheRangeAndTheRungsAscend(): void
    {
        foreach ([[24, 69], [279, 899], [1, 4], [4000, 9000], [13, 14]] as [$min, $max]) {
            $rungs = PriceLadder::rungs($min, $max);

            self::assertGreaterThanOrEqual($min, $rungs[0], "$min-$max");
            self::assertLessThanOrEqual($max, $rungs[array_key_last($rungs)], "$min-$max");
            $sorted = $rungs;
            sort($sorted);
            self::assertSame($sorted, $rungs);
            self::assertSame($rungs, array_values(array_unique($rungs)));
        }
    }

    public function testARangeWithoutARoundPriceHasOneRung(): void
    {
        self::assertSame([13], PriceLadder::rungs(13, 13));
        self::assertSame([14], PriceLadder::rungs(14, 14));
        self::assertSame([13], PriceLadder::rungs(13, 14));
    }

    public function testABadRangeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PriceLadder::rungs(50, 49);
    }

    public function testARangeStartsAtOneZloty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PriceLadder::rungs(0, 49);
    }

    public function testAPriceEndsWithNinetyNineOrWithTwoZeros(): void
    {
        self::assertSame(29_999, PriceLadder::gross(300, true));
        self::assertSame(30_000, PriceLadder::gross(300, false));
        self::assertSame(499, PriceLadder::gross(5, true));
    }
}
