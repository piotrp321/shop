<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalSalesHours;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The hourly sales on PostgreSQL, in the transaction DAMA rolls back after each test: an addition goes to the hour of
 * the payment (UTC) and to the shard of the order id, and the totals sum the hours of a period. The hours of the test
 * are far in the future, so no other row of shop_test is in them.
 */
final class DbalSalesHoursTest extends KernelTestCase
{
    private Connection $connection;
    private DbalSalesHours $sales;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->sales = new DbalSalesHours($connection);
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
    }

    public function testAnAdditionGoesToTheHourOfThePaymentAndToTheShardOfTheOrder(): void
    {
        $this->sales->add(self::order('a1'), self::at('2031-05-04 12:59:59+00:00'), 12_300);
        $this->sales->add(self::order('b1'), self::at('2031-05-04 12:00:00+00:00'), 700);
        $this->sales->add(self::order('0f'), self::at('2031-05-04 13:00:00+00:00'), 5_000);
        // In the Polish time zone: 15:30 CEST is 13:30 UTC.
        $this->sales->add(self::order('ff'), new \DateTimeImmutable('2031-05-04 15:30:00', new \DateTimeZone('Europe/Warsaw')), 1);

        self::assertSame([
            ['hour_start' => '2031-05-04 12:00:00+00', 'shard' => 1, 'order_count' => 2, 'gross' => 13_000],
            ['hour_start' => '2031-05-04 13:00:00+00', 'shard' => 15, 'order_count' => 2, 'gross' => 5_001],
        ], $this->rows());
    }

    public function testTheTotalsSumTheHoursFromTheStartToTheEndExclusive(): void
    {
        $this->sales->add(self::order('01'), self::at('2031-05-04 10:15:00+00:00'), 100);
        $this->sales->add(self::order('02'), self::at('2031-05-04 11:59:59+00:00'), 200);
        $this->sales->add(self::order('03'), self::at('2031-05-04 12:00:00+00:00'), 400);

        self::assertEquals(new SalesTotals(2, 300), $this->sales->totals(self::at('2031-05-04 10:00:00+00:00'), self::at('2031-05-04 12:00:00+00:00')));
        self::assertEquals(new SalesTotals(1, 400), $this->sales->totals(self::at('2031-05-04 12:00:00+00:00'), self::at('2031-05-04 13:00:00+00:00')));
        self::assertEquals(new SalesTotals(0, 0), $this->sales->totals(self::at('2031-05-04 13:00:00+00:00'), self::at('2031-05-05 13:00:00+00:00')));
    }

    public function testAnAdditionInATransactionThatRollsBackLeavesNothing(): void
    {
        $this->sales->add(self::order('01'), self::at('2031-05-04 10:15:00+00:00'), 100);

        $this->connection->beginTransaction();
        $this->sales->add(self::order('11'), self::at('2031-05-04 10:20:00+00:00'), 999);
        $this->connection->rollBack();

        self::assertEquals(new SalesTotals(1, 100), $this->sales->totals(self::at('2031-05-04 10:00:00+00:00'), self::at('2031-05-04 11:00:00+00:00')));
    }

    /**
     * An order id whose last byte is $lastByte (hexadecimal); its low four bits are the shard.
     */
    private static function order(string $lastByte): OrderId
    {
        return OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-'.bin2hex(random_bytes(5)).$lastByte);
    }

    private static function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative("SELECT to_char(hour_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' AS hour_start, shard, order_count, gross FROM ordering_sales_hour ORDER BY hour_start, shard");
    }
}
