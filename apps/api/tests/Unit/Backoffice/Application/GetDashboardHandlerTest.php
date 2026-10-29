<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\CatalogStatisticsProvider;
use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Port\SystemHealth;
use App\Backoffice\Application\Query\CatalogSection;
use App\Backoffice\Application\Query\GetDashboard;
use App\Backoffice\Application\Query\GetDashboardHandler;
use App\Backoffice\Application\Query\QueueStatus;
use App\Backoffice\Application\Query\SearchIndexStatus;
use App\Backoffice\Application\Query\ServiceStatus;
use PHPUnit\Framework\TestCase;

final class GetDashboardHandlerTest extends TestCase
{
    private const array SPEC_ORDER = ['postgres', 'redis', 'elasticsearch', 'rabbitmq', 'keycloak', 'garage', 'imgproxy', 'mailpit'];

    public function testWithoutSystemSectionTheSystemPortsAreNeverCalled(): void
    {
        $health = new RecordingSystemHealth([]);
        $messaging = new FakeMessagingStatistics(5, []);

        $dashboard = $this->handler(new CatalogStatistics(10, 3, 2, 10), $health, $messaging)(new GetDashboard(includeSystem: false));

        self::assertEquals(new CatalogSection(10, 3, 2), $dashboard->catalog);
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

        $dashboard = $this->handler(new CatalogStatistics(1, 1, 0, 1), $health, new FakeMessagingStatistics(0, []))(new GetDashboard(true));

        self::assertNotNull($dashboard->system);
        self::assertSame(self::SPEC_ORDER, array_map(static fn (ServiceStatus $service): string => $service->name, $dashboard->system->services));
    }

    public function testServiceStateIsPassedThrough(): void
    {
        $health = new RecordingSystemHealth([new ServiceStatus('redis', false, null), new ServiceStatus('postgres', true, 4)]);

        $dashboard = $this->handler(new CatalogStatistics(1, 1, 0, 1), $health, new FakeMessagingStatistics(0, []))(new GetDashboard(true));

        self::assertNotNull($dashboard->system);
        self::assertEquals([new ServiceStatus('postgres', true, 4), new ServiceStatus('redis', false, null)], $dashboard->system->services);
    }

    public function testOutboxAndQueuesComeFromMessagingStatistics(): void
    {
        $queues = [new QueueStatus('events', 2, 1)];

        $dashboard = $this->handler(new CatalogStatistics(1, 1, 0, 1), new RecordingSystemHealth([]), new FakeMessagingStatistics(7, $queues))(new GetDashboard(true));

        self::assertNotNull($dashboard->system);
        self::assertSame(7, $dashboard->system->outboxPending);
        self::assertEquals($queues, $dashboard->system->queues);
    }

    public function testUnavailableQueueStatisticsGiveNullQueues(): void
    {
        $dashboard = $this->handler(new CatalogStatistics(1, 1, 0, 1), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, null))(new GetDashboard(true));

        self::assertNotNull($dashboard->system);
        self::assertNull($dashboard->system->queues);
        self::assertSame(0, $dashboard->system->outboxPending);
    }

    public function testUnknownIndexedCountIsNeverInSync(): void
    {
        $dashboard = $this->handler(new CatalogStatistics(0, 0, 0, null), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true));

        self::assertNotNull($dashboard->system);
        self::assertEquals(new SearchIndexStatus(null, 0, false), $dashboard->system->searchIndex);
    }

    public function testEqualCountsAreInSync(): void
    {
        $dashboard = $this->handler(new CatalogStatistics(412, 36, 8, 412), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true));

        self::assertNotNull($dashboard->system);
        self::assertEquals(new SearchIndexStatus(412, 412, true), $dashboard->system->searchIndex);
    }

    public function testDifferentCountsAreNotInSync(): void
    {
        $dashboard = $this->handler(new CatalogStatistics(412, 36, 8, 400), new RecordingSystemHealth([]), new FakeMessagingStatistics(0, []))(new GetDashboard(true));

        self::assertNotNull($dashboard->system);
        self::assertEquals(new SearchIndexStatus(400, 412, false), $dashboard->system->searchIndex);
    }

    private function handler(CatalogStatistics $statistics, SystemHealth $health, MessagingStatistics $messaging): GetDashboardHandler
    {
        return new GetDashboardHandler(
            new class($statistics) implements CatalogStatisticsProvider {
                public function __construct(private readonly CatalogStatistics $statistics)
                {
                }

                public function statistics(): CatalogStatistics
                {
                    return $this->statistics;
                }
            },
            $health,
            $messaging,
        );
    }
}
