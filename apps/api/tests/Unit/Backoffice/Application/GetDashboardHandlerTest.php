<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\CatalogCounts;
use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Port\OrderQueueCounts;
use App\Backoffice\Application\Port\OrderQueues;
use App\Backoffice\Application\Port\StockQueueCounts;
use App\Backoffice\Application\Port\StockQueues;
use App\Backoffice\Application\Port\SystemHealth;
use App\Backoffice\Application\Query\CatalogSection;
use App\Backoffice\Application\Query\GetDashboard;
use App\Backoffice\Application\Query\GetDashboardHandler;
use App\Backoffice\Application\Query\QueueAccess;
use App\Backoffice\Application\Query\QueuesSection;
use App\Backoffice\Application\Query\QueueStatus;
use App\Backoffice\Application\Query\SearchIndexStatus;
use App\Backoffice\Application\Query\ServiceStatus;
use App\Shared\Domain\BoundedCount;
use PHPUnit\Framework\TestCase;

final class GetDashboardHandlerTest extends TestCase
{
    private const array SPEC_ORDER = ['postgres', 'redis', 'elasticsearch', 'rabbitmq', 'keycloak', 'garage', 'imgproxy', 'mailpit'];

    public function testWithoutSystemSectionTheSystemPortsAreNeverCalled(): void
    {
        $health = new RecordingSystemHealth([]);
        $messaging = new FakeMessagingStatistics(5, []);

        $dashboard = $this->handler(new CatalogCounts(10, 3, null, 10), $health, $messaging)(new GetDashboard(includeSystem: false, queues: QueueAccess::none()));

        self::assertEquals(new CatalogSection(10, 3), $dashboard->catalog);
        self::assertNull($dashboard->system);
        self::assertSame(0, $health->calls);
        self::assertSame(0, $messaging->calls);
    }

    public function testServicesAreInTheOrderOfTheSpecificationWhateverTheOrderOfTheProbes(): void
    {
        $shuffled = ['mailpit', 'redis', 'keycloak', 'postgres', 'imgproxy', 'rabbitmq', 'garage', 'elasticsearch'];
        $health = new RecordingSystemHealth(array_map(
            static fn (string $name): ServiceStatus => new ServiceStatus($name, true, 3),
            $shuffled,
        ));

        $dashboard = $this->handler(new CatalogCounts(1, 1, null, 1), $health, new FakeMessagingStatistics(0, []))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertSame(self::SPEC_ORDER, array_map(static fn (ServiceStatus $service): string => $service->name, $dashboard->system->services));
    }

    public function testServiceStateIsPassedThrough(): void
    {
        $health = new RecordingSystemHealth([new ServiceStatus('redis', false, null), new ServiceStatus('postgres', true, 4)]);

        $dashboard = $this->handler(new CatalogCounts(1, 1, null, 1), $health, new FakeMessagingStatistics(0, []))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertEquals([new ServiceStatus('postgres', true, 4), new ServiceStatus('redis', false, null)], $dashboard->system->services);
    }

    public function testOutboxAndQueuesComeFromMessagingStatistics(): void
    {
        $queues = [new QueueStatus('events', 2, 1)];

        $dashboard = $this->handler(new CatalogCounts(1, 1, null, 1), new RecordingSystemHealth([]), new FakeMessagingStatistics(7, $queues))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertSame(7, $dashboard->system->outboxPending);
        self::assertEquals($queues, $dashboard->system->queues);
    }

    public function testUnavailableQueueStatisticsGiveNullQueues(): void
    {
        $dashboard = $this->handler(new CatalogCounts(1, 1, null, 1), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, null))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertNull($dashboard->system->queues);
        self::assertSame(0, $dashboard->system->outboxPending);
    }

    public function testUnknownIndexedCountIsNeverInSync(): void
    {
        $dashboard = $this->handler(new CatalogCounts(0, 0, null, null), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertEquals(new SearchIndexStatus(null, 0, false), $dashboard->system->searchIndex);
    }

    public function testEqualCountsAreInSync(): void
    {
        $dashboard = $this->handler(new CatalogCounts(412, 36, null, 412), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertEquals(new SearchIndexStatus(412, 412, true), $dashboard->system->searchIndex);
    }

    public function testDifferentCountsAreNotInSync(): void
    {
        $dashboard = $this->handler(new CatalogCounts(412, 36, null, 400), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertEquals(new SearchIndexStatus(400, 412, false), $dashboard->system->searchIndex);
    }

    public function testIndexWithinOnePercentOfTheEstimateIsInSync(): void
    {
        foreach ([[10_000_000, 9_900_000, true], [10_000_000, 9_899_999, false], [10_000_000, 10_100_000, true], [10_000_000, 10_100_001, false], [400, 396, true], [400, 395, false], [0, 0, true], [0, 1, false]] as [$estimate, $indexed, $inSync]) {
            $dashboard = $this->handler(new CatalogCounts($estimate, 36, null, $indexed), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true, QueueAccess::none()));

            self::assertNotNull($dashboard->system);
            self::assertEquals(new SearchIndexStatus($indexed, $estimate, $inSync), $dashboard->system->searchIndex, "estimate $estimate, indexed $indexed");
        }
    }

    public function testWithoutAnEstimateTheIndexIsNeitherInSyncNorOutOfIt(): void
    {
        $dashboard = $this->handler(new CatalogCounts(null, 36, null, 412), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true, QueueAccess::none()));

        self::assertNotNull($dashboard->system);
        self::assertEquals(new SearchIndexStatus(412, null, null), $dashboard->system->searchIndex);
        self::assertEquals(new CatalogSection(null, 36), $dashboard->catalog);
    }

    public function testAMemberWithNoQueueAccessGetsNoQueueAndTheOwnersAreNeverAsked(): void
    {
        $orders = new RecordingOrderQueues(new OrderQueueCounts(new BoundedCount(1, false), new BoundedCount(2, false), new BoundedCount(3, false), new BoundedCount(4, false)));
        $stock = new RecordingStockQueues(new StockQueueCounts(new BoundedCount(5, false), new BoundedCount(6, false)));
        $catalog = new RecordingCatalogStatistics(new CatalogCounts(10, 3, new BoundedCount(7, false), 10));

        $dashboard = $this->handler($catalog, new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []), $orders, $stock)(new GetDashboard(false, QueueAccess::none()));

        self::assertEquals(new QueuesSection(null, null, null, null, null, null, null), $dashboard->queues);
        self::assertSame([], $orders->asked);
        self::assertSame(0, $stock->calls);
        self::assertSame([false], $catalog->asked, 'the products without images are not counted');
    }

    public function testEveryQueueComesFromItsOwnerOnlyWithItsAccess(): void
    {
        $orders = new RecordingOrderQueues(new OrderQueueCounts(new BoundedCount(1, false), new BoundedCount(2, false), new BoundedCount(3, false), new BoundedCount(1000, true)));
        $stock = new RecordingStockQueues(new StockQueueCounts(new BoundedCount(5, false), new BoundedCount(6, false)));
        $catalog = new RecordingCatalogStatistics(new CatalogCounts(10, 3, new BoundedCount(7, false), 10));

        $dashboard = $this->handler($catalog, new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []), $orders, $stock)(
            new GetDashboard(false, new QueueAccess(viewOrders: true, viewPendingPayments: true, completeRefunds: true, manageStock: true, manageProducts: true)),
        );

        self::assertEquals(new QueuesSection(
            new BoundedCount(1, false),
            new BoundedCount(2, false),
            new BoundedCount(3, false),
            new BoundedCount(1000, true),
            new BoundedCount(5, false),
            new BoundedCount(6, false),
            new BoundedCount(7, false),
        ), $dashboard->queues);
        self::assertSame([[true, true, true]], $orders->asked);
        self::assertSame([true], $catalog->asked);
    }

    /**
     * @return iterable<string, array{QueueAccess, array{bool, bool, bool}, QueuesSection}> the access of a role, what is asked of Ordering and the queues it gets
     */
    public static function rolesOfTheStaff(): iterable
    {
        $paid = new BoundedCount(1, false);
        $new = new BoundedCount(2, false);
        $pending = new BoundedCount(3, false);
        $overdue = new BoundedCount(4, false);
        $out = new BoundedCount(5, false);
        $low = new BoundedCount(6, false);
        $images = new BoundedCount(7, false);

        yield 'support' => [new QueueAccess(true, true, true, false, false), [true, true, true], new QueuesSection($paid, $new, $pending, $overdue, null, null, null)];
        yield 'warehouse' => [new QueueAccess(true, false, false, true, false), [true, false, false], new QueuesSection($paid, null, null, null, $out, $low, null)];
        yield 'marketing' => [new QueueAccess(false, false, false, false, true), [false, false, false], new QueuesSection(null, null, null, null, null, null, $images)];
        yield 'refunds only' => [new QueueAccess(false, false, true, false, false), [false, false, true], new QueuesSection(null, null, $pending, $overdue, null, null, null)];
    }

    /**
     * @param array{bool, bool, bool} $askedOfOrders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rolesOfTheStaff')]
    public function testOnlyTheQueuesOfTheAccessAreAskedForAndShown(QueueAccess $access, array $askedOfOrders, QueuesSection $expected): void
    {
        $orders = new RecordingOrderQueues(new OrderQueueCounts(new BoundedCount(1, false), new BoundedCount(2, false), new BoundedCount(3, false), new BoundedCount(4, false)));
        $stock = new RecordingStockQueues(new StockQueueCounts(new BoundedCount(5, false), new BoundedCount(6, false)));
        $catalog = new RecordingCatalogStatistics(new CatalogCounts(10, 3, new BoundedCount(7, false), 10));

        $dashboard = $this->handler($catalog, new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []), $orders, $stock)(new GetDashboard(false, $access));

        self::assertEquals($expected, $dashboard->queues);
        self::assertSame([] === array_filter($askedOfOrders) ? [] : [$askedOfOrders], $orders->asked);
    }

    private function handler(CatalogCounts|CatalogStatistics $statistics, SystemHealth $health, MessagingStatistics $messaging, ?OrderQueues $orders = null, ?StockQueues $stock = null): GetDashboardHandler
    {
        return new GetDashboardHandler(
            $statistics instanceof CatalogStatistics ? $statistics : new RecordingCatalogStatistics($statistics),
            $health,
            $messaging,
            $orders ?? new RecordingOrderQueues(new OrderQueueCounts(null, null, null, null)),
            $stock ?? new RecordingStockQueues(new StockQueueCounts(new BoundedCount(0, false), new BoundedCount(0, false))),
        );
    }
}
