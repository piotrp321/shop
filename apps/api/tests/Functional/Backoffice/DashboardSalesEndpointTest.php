<?php

declare(strict_types=1);

namespace App\Tests\Functional\Backoffice;

use App\Ordering\Infrastructure\SystemClock;
use App\Tests\Support\Security\TestTokenFactory;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sales of the dashboard (GET /api/v1/admin/dashboard, `sales`): only an administrator sees them (VIEW_SALES of
 * Ordering), every other role gets null; the figures come from the hourly sales up to the start of the current hour of
 * the clock of Ordering, fixed here. On shop_test, rolled back after each test.
 */
final class DashboardSalesEndpointTest extends WebTestCase
{
    /** 14:35 in Warsaw: today runs from 23:00 UTC of the 14th to 13:00 UTC. */
    private const string NOW = '2026-11-15 13:35:00+00:00';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rolesWithoutSales(): iterable
    {
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
        yield 'marketing' => [['marketing']];
        yield 'every other role together' => [['support', 'warehouse', 'marketing']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesWithoutSales')]
    public function testEveryRoleButAnAdministratorGetsNoSales(array $roles): void
    {
        $this->clockAt(self::NOW);
        $this->sales('2026-11-15 10:00:00', 3, 12_300);

        self::assertNull($this->dashboard(TestTokenFactory::staff($roles))['sales']);
    }

    public function testAnAdministratorGetsTheSalesOfTheCompletedHoursComparedWithThePeriodsBefore(): void
    {
        $this->clockAt(self::NOW);
        $this->sales('2026-11-15 10:00:00', 3, 1_000, 2);
        $this->sales('2026-11-15 12:00:00', 7, 2_000, 1);
        // The current hour has not ended.
        $this->sales('2026-11-15 13:00:00', 0, 50_000, 5);
        // Yesterday before 14:00 of the clock.
        $this->sales('2026-11-14 09:00:00', 1, 2_000, 1);
        // Yesterday after 14:00: in the 7 days, not in the comparison of today.
        $this->sales('2026-11-14 15:00:00', 1, 4_000, 1);

        $sales = $this->dashboard(TestTokenFactory::staff(['admin']))['sales'];

        self::assertSame([
            'dataUntil' => '2026-11-15T13:00:00+00:00',
            'currency' => 'PLN',
            'today' => ['revenue' => 4_000, 'orderCount' => 3, 'averageOrderValue' => 1_333, 'changeBasisPoints' => 10_000],
            'last7Days' => ['revenue' => 10_000, 'orderCount' => 5, 'averageOrderValue' => 2_000, 'changeBasisPoints' => null],
            'last30Days' => ['revenue' => 10_000, 'orderCount' => 5, 'averageOrderValue' => 2_000, 'changeBasisPoints' => null],
        ], $sales);
    }

    public function testBetweenMidnightAndOneTodayIsEmpty(): void
    {
        $this->clockAt('2026-11-14 23:40:00+00:00');
        $this->sales('2026-11-14 23:00:00', 0, 1_000);

        $sales = $this->dashboard(TestTokenFactory::staff(['admin']))['sales'];

        self::assertIsArray($sales);
        self::assertSame('2026-11-14T23:00:00+00:00', $sales['dataUntil']);
        self::assertSame(['revenue' => null, 'orderCount' => null, 'averageOrderValue' => null, 'changeBasisPoints' => null], $sales['today']);
        self::assertSame(['revenue' => 0, 'orderCount' => 0, 'averageOrderValue' => null, 'changeBasisPoints' => null], $sales['last7Days']);
    }

    public function testTheSalesMatchTheSchemaOfTheExportedSpecification(): void
    {
        $this->clockAt(self::NOW);
        $sales = $this->dashboard(TestTokenFactory::staff(['admin']))['sales'];

        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        $schemas = $spec['components']['schemas'];
        self::assertIsArray($schemas);
        self::assertIsArray($schemas['DashboardSalesResponse']);
        self::assertIsArray($schemas['DashboardSalesResponse']['properties']);
        self::assertIsArray($schemas['DashboardSalesPeriodResponse']);
        self::assertIsArray($schemas['DashboardSalesPeriodResponse']['properties']);
        self::assertIsArray($sales);
        self::assertEqualsCanonicalizing(array_keys($schemas['DashboardSalesResponse']['properties']), array_keys($sales));
        self::assertIsArray($sales['today']);
        self::assertEqualsCanonicalizing(array_keys($schemas['DashboardSalesPeriodResponse']['properties']), array_keys($sales['today']));
    }

    /**
     * The clock of Ordering, before the first request builds the handlers that read it.
     */
    private function clockAt(string $now): void
    {
        self::getContainer()->set(SystemClock::class, new FixedOrderingClock(new \DateTimeImmutable($now)));
    }

    /**
     * $orders orders of $gross each in the hour (UTC), in one shard.
     */
    private function sales(string $hour, int $shard, int $gross, int $orders = 1): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ordering_sales_hour (hour_start, shard, order_count, gross) VALUES (?, ?, ?, ?)',
            [$hour.'+00', $shard, $orders, $orders * $gross],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(string $token): array
    {
        $this->client->request('GET', '/api/v1/admin/dashboard', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
