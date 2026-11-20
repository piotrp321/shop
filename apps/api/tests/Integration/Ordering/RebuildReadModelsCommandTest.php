<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Infrastructure\Backfill\RebuildCustomersStep;
use App\Ordering\Infrastructure\Backfill\RebuildSalesStep;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ordering:read-models:rebuild through the kernel on shop_test (rolled back after each test): the hourly sales are
 * rebuilt from the paid orders and then the customers from their orders, and a failure of either ends the command
 * with 1 and a message. The steps of app:backfill run the same rebuilds.
 */
final class RebuildReadModelsCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
        $this->connection->executeStatement('DELETE FROM ordering_customer');
    }

    public function testRebuildsTheHourlySalesFromThePaidOrders(): void
    {
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:10:00+00:00');
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:20:00+00:00');
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 18:00:00+00:00');
        OrderingFixture::order($this->connection);
        $this->connection->executeStatement("INSERT INTO ordering_sales_hour VALUES ('2026-11-01 05:00:00+00', 4, 3, 999)");

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('sales: 3 hours rebuilt, 3 orders, 3690 grosze', $tester->getDisplay());
        self::assertSame(
            [['2026-11-14 12:00', 2, 2_460], ['2026-11-14 18:00', 1, 1_230]],
            $this->connection->fetchAllNumeric("SELECT to_char(hour_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI'), CAST(SUM(order_count) AS int), CAST(SUM(gross) AS int) FROM ordering_sales_hour GROUP BY hour_start ORDER BY hour_start"),
        );
    }

    public function testRebuildsTheCustomersFromTheirOrdersAfterTheSales(): void
    {
        OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: 'kc-command-a', fullName: 'Anna Nowak', placedAt: '2026-11-14 12:00:00+00:00', paid: true, paidAt: '2026-11-14 12:10:00+00:00');
        OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: 'kc-command-a', fullName: 'Anna Nowak', placedAt: '2026-11-14 13:00:00+00:00');
        OrderingFixture::order($this->connection, email: 'gosc@example.com', paid: true, paidAt: '2026-11-14 12:20:00+00:00');

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('customers: 1 customers rebuilt in 1 batches, 2 orders', $display);
        self::assertLessThan(strpos($display, 'customers: 1 customers'), (int) strpos($display, 'sales: 1 hours rebuilt'));
        self::assertSame(
            [['kc-command-a', 'anna@example.com', 2, 1, 1_230]],
            $this->connection->fetchAllNumeric('SELECT customer_id, email, order_count, paid_count, CAST(paid_gross AS int) FROM ordering_customer ORDER BY customer_id'),
        );
    }

    public function testAFailureOfTheCustomersEndsWithOneAndAMessage(): void
    {
        OrderingFixture::order($this->connection, customerId: 'kc-command-a');
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.fail_customers() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of the customers'; END \$\$");
        $this->connection->executeStatement('CREATE TRIGGER fail_customers BEFORE INSERT ON ordering_customer FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_customers()');

        $tester = $this->applicationTester();

        self::assertSame(Command::FAILURE, $tester->run(['command' => 'ordering:read-models:rebuild']));
        // The error block wraps the message: compared with its lines joined.
        self::assertStringContainsString('forced failure of the customers', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testTheBackfillStepRunsTheCustomerRebuild(): void
    {
        OrderingFixture::order($this->connection, customerId: 'kc-command-a');
        $step = self::getContainer()->get(RebuildCustomersStep::class);
        self::assertInstanceOf(RebuildCustomersStep::class, $step);
        $lines = [];

        $step->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('ordering:read-models:rebuild:customers', $step->name());
        self::assertSame(['customers: 1 customers rebuilt in 1 batches, 1 orders'], $lines);
        self::assertSame(1, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_customer'));
    }

    public function testAFailureEndsWithOneAndAMessage(): void
    {
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:10:00+00:00');
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.fail_sales() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of the sales'; END \$\$");
        $this->connection->executeStatement('CREATE TRIGGER fail_sales BEFORE INSERT ON ordering_sales_hour FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_sales()');

        $tester = $this->applicationTester();

        // The command hands the error on to the console, which ends with 1 (a CommandTester would not catch it).
        self::assertSame(Command::FAILURE, $tester->run(['command' => 'ordering:read-models:rebuild']));
        self::assertStringContainsString('forced failure of the sales', $tester->getDisplay());
    }

    public function testTheBackfillStepRunsTheSalesRebuild(): void
    {
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:10:00+00:00');
        $step = self::getContainer()->get(RebuildSalesStep::class);
        self::assertInstanceOf(RebuildSalesStep::class, $step);
        $lines = [];

        $step->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('ordering:read-models:rebuild:sales', $step->name());
        self::assertSame(['sales: 1 hours rebuilt, 1 orders, 1230 grosze'], $lines);
        self::assertSame(1, OrderingFixture::count($this->connection, 'SELECT CAST(SUM(order_count) AS int) FROM ordering_sales_hour'));
    }

    private function applicationTester(): ApplicationTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('ordering:read-models:rebuild'));
    }
}
