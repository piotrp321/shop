<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Query\GetOrderQueues;
use App\Ordering\Application\Query\GetOrderQueuesHandler;
use App\Ordering\Application\Query\OrderQueues;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\BoundedCount;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryBackofficeOrderReader;
use PHPUnit\Framework\TestCase;

/**
 * The queues of the dashboard count with the filters of the lists they link to, up to 1000, and only the ones asked for.
 */
final class GetOrderQueuesHandlerTest extends TestCase
{
    private const string NOW = '2026-11-20 09:00:00+00:00';

    private InMemoryBackofficeOrderReader $reader;

    protected function setUp(): void
    {
        $this->reader = new InMemoryBackofficeOrderReader();
    }

    public function testEveryQueueCountsWithTheFilterOfItsListUpToTheLimitOfTheDashboard(): void
    {
        $this->reader->total = new BoundedCount(7, false);

        $queues = $this->handler()(new GetOrderQueues(true, true, true, true));

        self::assertEquals(new OrderQueues(new BoundedCount(7, false), new BoundedCount(7, false), new BoundedCount(7, false), new BoundedCount(7, false)), $queues);
        self::assertEquals([
            [new BackofficeOrderFilter(OrderStatus::Paid, false, null, null), 1000],
            [new BackofficeOrderFilter(OrderStatus::New, false, null, null), 1000],
            [new BackofficeOrderFilter(null, true, null, null), 1000],
            [new BackofficeOrderFilter(null, true, null, null, null, null, null, null, new \DateTimeImmutable(self::NOW)), 1000],
        ], array_map(null, $this->reader->counts, $this->reader->countLimits));
    }

    public function testAQueueNotAskedForIsNullAndNeverCounted(): void
    {
        $queues = $this->handler()(new GetOrderQueues(false, true, false, false));

        self::assertNull($queues->paidToShip);
        self::assertNotNull($queues->awaitingPayment);
        self::assertNull($queues->pendingRefunds);
        self::assertNull($queues->overdueRefunds);
        self::assertCount(1, $this->reader->counts);
    }

    public function testNothingAskedForCountsNothing(): void
    {
        $queues = $this->handler()(new GetOrderQueues(false, false, false, false));

        self::assertEquals(new OrderQueues(null, null, null, null), $queues);
        self::assertSame([], $this->reader->counts);
    }

    public function testACappedCountIsPassedOn(): void
    {
        $this->reader->total = new BoundedCount(1000, true);

        $queues = $this->handler()(new GetOrderQueues(true, false, false, false));

        self::assertEquals(new BoundedCount(1000, true), $queues->paidToShip);
    }

    private function handler(): GetOrderQueuesHandler
    {
        return new GetOrderQueuesHandler($this->reader, new FixedOrderingClock(new \DateTimeImmutable(self::NOW)));
    }
}
