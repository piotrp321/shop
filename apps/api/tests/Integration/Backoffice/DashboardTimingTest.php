<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backoffice;

use App\Backoffice\Application\Port\CatalogCounts;
use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Port\SystemHealth;
use App\Backoffice\Application\Query\GetDashboard;
use App\Backoffice\Application\Query\GetDashboardHandler;
use App\Backoffice\Infrastructure\MessagingStatisticsAdapter;
use App\Backoffice\Infrastructure\ProbingSystemHealth;
use App\Shared\Infrastructure\Health\Probe\ElasticsearchProbe;
use App\Shared\Infrastructure\Health\Probe\GarageProbe;
use App\Shared\Infrastructure\Health\Probe\ImgproxyProbe;
use App\Shared\Infrastructure\Health\Probe\KeycloakProbe;
use App\Shared\Infrastructure\Health\Probe\MailpitProbe;
use App\Shared\Infrastructure\Health\Probe\PostgresProbe;
use App\Shared\Infrastructure\Health\Probe\RabbitMqProbe;
use App\Shared\Infrastructure\Health\Probe\RedisProbe;
use App\Shared\Infrastructure\Health\ServiceProbe;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * How long the dashboard takes when services hang instead of refusing connections: every probe and the queue
 * request wait together, so the system section costs about one limit (1 s), not one per service.
 */
final class DashboardTimingTest extends KernelTestCase
{
    /** @var list<resource> */
    private array $listeners = [];

    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            fclose($listener);
        }
        $this->listeners = [];
        parent::tearDown();
    }

    public function testHungServicesCostOneLimitInTotal(): void
    {
        self::bootKernel();
        // One address per service: the client opens at most 6 connections to the same host at a time, which would
        // make the seventh request wait for another one.
        $client = HttpClient::create();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $handler = new GetDashboardHandler(
            $this->instantCatalog(),
            new ProbingSystemHealth([
                new PostgresProbe($connection),
                new RedisProbe('127.0.0.1', (int) explode(':', $this->hangingAddress())[1]),
                new ElasticsearchProbe($client, $this->hangingUrl()),
                new RabbitMqProbe($client, $this->hangingUrl(), 'user', 'secret'),
                new KeycloakProbe($client, $this->hangingUrl()),
                new GarageProbe($client, $this->hangingUrl()),
                new ImgproxyProbe($client, $this->hangingUrl()),
                new MailpitProbe($client, $this->hangingUrl()),
            ]),
            new MessagingStatisticsAdapter($connection, $client, $this->hangingUrl(), 'user', 'secret', 'test'),
        );

        $started = microtime(true);
        $dashboard = $handler(new GetDashboard(true));
        $elapsed = microtime(true) - $started;

        self::assertNotNull($dashboard->system);
        self::assertNull($dashboard->system->queues);
        $up = array_map(static fn ($service): bool => $service->up, $dashboard->system->services);
        self::assertSame(['postgres' => true], array_filter(array_combine(array_map(static fn ($s): string => $s->name, $dashboard->system->services), $up)));
        self::assertGreaterThan(0.9, $elapsed, 'The hung services should have used up their limit.');
        self::assertLessThan(1.5, $elapsed, 'The probes and the queue request must wait together.');
    }

    public function testAHungRedisDoesNotMakeTheHealthyServicesLookDown(): void
    {
        self::bootKernel();
        $probes = [];
        foreach (['Postgres', 'Elasticsearch', 'RabbitMq', 'Keycloak', 'Garage', 'Imgproxy', 'Mailpit'] as $name) {
            $probe = self::getContainer()->get('test.App\\Shared\\Infrastructure\\Health\\Probe\\'.$name.'Probe');
            self::assertInstanceOf(ServiceProbe::class, $probe);
            $probes[] = $probe;
        }
        array_unshift($probes, new RedisProbe('127.0.0.1', (int) explode(':', $this->hangingAddress())[1]));

        $started = microtime(true);
        $statuses = (new ProbingSystemHealth($probes))->start()();
        $elapsed = microtime(true) - $started;

        $byName = [];
        foreach ($statuses as $status) {
            $byName[$status->name] = $status->up;
        }
        ksort($byName);
        self::assertSame(['elasticsearch' => true, 'garage' => true, 'imgproxy' => true, 'keycloak' => true, 'mailpit' => true, 'postgres' => true, 'rabbitmq' => true, 'redis' => false], $byName);
        self::assertLessThan(1.5, $elapsed);
    }

    public function testRedisLatencyIsItsOwnWhileAnotherServiceHangs(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('test.App\\Shared\\Infrastructure\\Health\\Probe\\RedisProbe');
        self::assertInstanceOf(ServiceProbe::class, $redis);
        $hanging = new ElasticsearchProbe(HttpClient::create(), $this->hangingUrl());

        $started = microtime(true);
        $statuses = (new ProbingSystemHealth([$redis, $hanging]))->start()();
        $elapsed = microtime(true) - $started;

        $byName = [];
        foreach ($statuses as $status) {
            $byName[$status->name] = $status;
        }
        // The hanging service holds the collection for its whole limit, but Redis answered long before.
        self::assertGreaterThan(0.9, $elapsed);
        self::assertTrue($byName['redis']->up);
        self::assertNotNull($byName['redis']->latencyMs);
        self::assertLessThan(200, $byName['redis']->latencyMs);
        self::assertFalse($byName['elasticsearch']->up);
    }

    public function testASlowCatalogDelaysOnlyTheCatalogNotTheSystemSection(): void
    {
        self::bootKernel();
        $health = self::getContainer()->get(SystemHealth::class);
        $messaging = self::getContainer()->get(MessagingStatistics::class);
        self::assertInstanceOf(SystemHealth::class, $health);
        self::assertInstanceOf(MessagingStatistics::class, $messaging);
        $slowCatalog = new class implements CatalogStatistics {
            public function counts(): CatalogCounts
            {
                usleep(1_800_000); // an Elasticsearch count that waits for its 2 s limit

                return new CatalogCounts(1, 1, 0, null);
            }
        };

        $started = microtime(true);
        $dashboard = (new GetDashboardHandler($slowCatalog, $health, $messaging))(new GetDashboard(true));
        $elapsed = microtime(true) - $started;

        self::assertNotNull($dashboard->system);
        self::assertSame([true], array_values(array_unique(array_map(static fn ($service): bool => $service->up, $dashboard->system->services))));
        self::assertLessThan(2.5, $elapsed);
    }

    private function instantCatalog(): CatalogStatistics
    {
        return new class implements CatalogStatistics {
            public function counts(): CatalogCounts
            {
                return new CatalogCounts(1, 1, 0, 1);
            }
        };
    }

    private function hangingUrl(): string
    {
        return 'http://'.$this->hangingAddress();
    }

    /**
     * A local address that accepts connections and never answers.
     */
    private function hangingAddress(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertNotFalse($server, (string) $error);
        $this->listeners[] = $server;
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);

        return $address;
    }
}
