<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Query\DeliveryCostShare;
use App\Pricing\Application\Query\SplitDeliveryCost;
use App\Pricing\Application\Query\SplitDeliveryCostHandler;
use PHPUnit\Framework\TestCase;

final class SplitDeliveryCostHandlerTest extends TestCase
{
    public function testTheSharesOfTheCostAreGivenByRateInDescendingOrder(): void
    {
        $split = new SplitDeliveryCostHandler()(new SplitDeliveryCost(1999, [800 => 5_000, 2300 => 10_000]));

        self::assertEquals([new DeliveryCostShare(2300, 1333, 1084, 249), new DeliveryCostShare(800, 666, 617, 49)], $split->shares);
    }

    public function testAFreeDeliveryHasNoShares(): void
    {
        self::assertSame([], new SplitDeliveryCostHandler()(new SplitDeliveryCost(0, [2300 => 10_000]))->shares);
    }

    public function testGoodsWorthNothingAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SplitDeliveryCostHandler()(new SplitDeliveryCost(1999, [2300 => 0]));
    }
}
