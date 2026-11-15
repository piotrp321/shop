<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Infrastructure\Backfill\RebuildSalesStep;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ordering:read-models:rebuild through the kernel on shop_test (rolled back after each test): the hourly sales are
 * rebuilt from the paid orders, and a failure ends the command with 1 and a message. The step of app:backfill runs
 * the same rebuild.
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

    public function testAFailureEndsWithOneAndAMessage(): void
    {
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:10:00+00:00');
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.fail_sales() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of the sales'; END \$\$");
        $this->connection->executeStatement('CREATE TRIGGER fail_sales BEFORE INSERT ON ordering_sales_hour FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_sales()');

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
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

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('ordering:read-models:rebuild'));
    }
}
