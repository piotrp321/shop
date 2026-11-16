<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Query\GetStockQueues;
use App\Inventory\Application\Query\GetStockQueuesHandler;
use App\Inventory\Application\Query\StockQueues;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Shared\Domain\BoundedCount;
use App\Tests\Unit\Inventory\InMemoryStockLevelProducts;
use PHPUnit\Framework\TestCase;

/**
 * The queues of the dashboard count the variants of the two levels of the stock list, up to 1000.
 */
final class GetStockQueuesHandlerTest extends TestCase
{
    public function testTheVariantsOfBothLevelsAreCountedUpToTheLimitOfTheDashboard(): void
    {
        $levels = new InMemoryStockLevelProducts()
            ->withVariants(AvailabilityLevel::OutOfStock, 3)
            ->withVariants(AvailabilityLevel::LowStock, 12);

        $queues = new GetStockQueuesHandler($levels)(new GetStockQueues());

        self::assertEquals(new StockQueues(new BoundedCount(3, false), new BoundedCount(12, false)), $queues);
        self::assertSame([['out_of_stock', 1000], ['low_stock', 1000]], $levels->variantCounts);
    }

    public function testMoreThanTheLimitIsCapped(): void
    {
        $levels = new InMemoryStockLevelProducts()
            ->withVariants(AvailabilityLevel::OutOfStock, 5000)
            ->withVariants(AvailabilityLevel::LowStock, 1000);

        $queues = new GetStockQueuesHandler($levels)(new GetStockQueues());

        self::assertEquals(new StockQueues(new BoundedCount(1000, true), new BoundedCount(1000, false)), $queues);
    }

    public function testNothingAtTheLevelsIsZero(): void
    {
        $queues = new GetStockQueuesHandler(new InMemoryStockLevelProducts())(new GetStockQueues());

        self::assertEquals(new StockQueues(new BoundedCount(0, false), new BoundedCount(0, false)), $queues);
    }
}
