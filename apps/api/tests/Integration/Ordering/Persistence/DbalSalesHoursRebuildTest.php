<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Application\ReadModel\SalesRebuild;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalSalesHours;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The rebuild of the hourly sales from the orders on PostgreSQL, in the transaction DAMA rolls back after each test:
 * the table it leaves is the one the payments wrote (the same rows: an hour and a shard with orders, nothing for a
 * shard without), whatever the table held before, and a second run leaves it as it is. The sales table is emptied
 * first; the orders of the test are the only paid orders of shop_test.
 */
final class DbalSalesHoursRebuildTest extends KernelTestCase
{
    private const int GROSS = 1_230;

    private Connection $connection;
    private DbalSalesHours $sales;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->sales = new DbalSalesHours($connection);
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
        self::assertSame(0, OrderingFixture::count($connection, 'SELECT COUNT(*) FROM ordering_order WHERE paid_at IS NOT NULL'), 'shop_test has no paid orders of its own');
    }

    public function testTheRebuildLeavesTheTableThePaymentsWroteWhateverItHeldAndASecondRunChangesNothing(): void
    {
        // Paid through MarkOrderPaid now, as the webhooks pay them.
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        for ($i = 0; $i < 20; ++$i) {
            $bus->dispatch(new MarkOrderPaid(OrderingFixture::order($this->connection), Uuid::v7()->toRfc4122(), self::GROSS, 'PLN', new \DateTimeImmutable()));
        }
        // Paid in earlier hours, added as MarkOrderPaid adds them: on the hour, a second before the next, a day apart.
        foreach (['2026-11-14 12:00:00', '2026-11-14 12:59:59', '2026-11-14 13:00:00', '2026-11-13 08:30:00', '2026-11-13 08:31:00'] as $paidAt) {
            $orderId = OrderingFixture::order($this->connection, paid: true, paidAt: $paidAt.'+00:00');
            $this->sales->add(OrderId::fromString($orderId), new \DateTimeImmutable($paidAt.'+00:00'), self::GROSS);
        }
        $written = $this->rows();
        self::assertSame(25, array_sum(array_column($written, 'order_count')));

        // What a rebuild must undo: an hour without orders, a row too high, a row of a shard without orders.
        $this->connection->executeStatement("INSERT INTO ordering_sales_hour VALUES ('2026-11-10 05:00:00+00', 4, 3, 999)");
        $this->connection->executeStatement("UPDATE ordering_sales_hour SET order_count = order_count + 5, gross = gross + 7 WHERE hour_start = '2026-11-14 12:00:00+00'");
        $this->connection->executeStatement("INSERT INTO ordering_sales_hour SELECT '2026-11-13 08:00:00+00', s, 1, 1 FROM generate_series(0, 15) s ON CONFLICT DO NOTHING");

        $summary = new SalesRebuild($this->sales)->run(static function (): void {});

        self::assertSame($written, $this->rows());
        self::assertSame(\sprintf('sales: 5 hours rebuilt, 25 orders, %d grosze', 25 * self::GROSS), $summary, 'the current hour, 12:00, 13:00, 8:00 and the stale 5:00');

        new SalesRebuild($this->sales)->run(static function (): void {});

        self::assertSame($written, $this->rows(), 'a second run changes nothing');
    }

    public function testTheRebuildOfAnHourCountsItsOrdersByTheShardOfTheirIdAndRemovesTheRowsWithout(): void
    {
        $orders = [];
        for ($i = 0; $i < 40; ++$i) {
            $orders[] = OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:'.str_pad((string) $i, 2, '0', \STR_PAD_LEFT).':00+00:00');
        }
        $this->connection->executeStatement("INSERT INTO ordering_sales_hour SELECT '2026-11-14 12:00:00+00', s, 9, 9 FROM generate_series(0, 15) s");

        self::assertEquals(new SalesTotals(40, 40 * self::GROSS), $this->sales->rebuild(new \DateTimeImmutable('2026-11-14 12:00:00+00:00')));

        $expected = [];
        foreach ($orders as $orderId) {
            $shard = (int) hexdec($orderId[35]);
            $expected[$shard] = ($expected[$shard] ?? 0) + 1;
        }
        ksort($expected);
        self::assertSame($expected, $this->connection->fetchAllKeyValue("SELECT shard, order_count FROM ordering_sales_hour WHERE hour_start = '2026-11-14 12:00:00+00' ORDER BY shard"));
    }

    public function testTheNextHourIsTheFirstWithAPaidOrderOrARowFromTheGivenHourOn(): void
    {
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:59:59+00:00');
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 15:00:00+00:00');
        // Not paid: no hour.
        OrderingFixture::order($this->connection);
        $this->connection->executeStatement("INSERT INTO ordering_sales_hour VALUES ('2026-11-14 13:00:00+00', 2, 1, 1)");

        self::assertEquals(self::hour('2026-11-14 12:00'), $this->sales->nextHour(null));
        self::assertEquals(self::hour('2026-11-14 12:00'), $this->sales->nextHour(self::hour('2026-11-14 12:00')));
        self::assertEquals(self::hour('2026-11-14 13:00'), $this->sales->nextHour(self::hour('2026-11-14 13:00')), 'a row without orders');
        self::assertEquals(self::hour('2026-11-14 15:00'), $this->sales->nextHour(self::hour('2026-11-14 14:00')));
        self::assertNull($this->sales->nextHour(self::hour('2026-11-14 16:00')));
    }

    private static function hour(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc.':00+00:00');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative("SELECT to_char(hour_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS hour_start, shard, order_count, gross FROM ordering_sales_hour ORDER BY hour_start, shard");
    }
}
