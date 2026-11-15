<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Infrastructure\Persistence\Doctrine\DbalSalesHours;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The statements of the sales rebuild on 100 000 paid orders, with the plan the planner chooses on its own statistics
 * (after ANALYZE): neither the search for the next hour nor the count of an hour scans the orders, both go through the
 * partial index by paid_at, so the rebuild of 50 million orders reads each order about once.
 *
 * The orders are in a temporary table that takes the place of ordering_order for the connection of the test (a copy of
 * its definition with its indexes), as in OrderQueuesPlanTest.
 */
final class SalesRebuildPlanTest extends KernelTestCase
{
    private const int ORDERS = 100_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE ordering_order (LIKE public.ordering_order INCLUDING ALL)');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ordering_order (
                    id, number, status, owner_type, access_token, email, full_name, phone, street, postal_code, city, country, currency,
                    total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, paid_at, payment_id, version
                )
                SELECT gen_random_uuid(), 'R-'||(100000 + n), CASE WHEN n % 10 = 0 THEN 'new' ELSE 'paid' END, 'guest',
                       substr(md5('t'||n)||md5('u'||n), 1, 43), 'jan@example.com', 'Jan Kowalski', '+48600100200', 'Długa 1', '00-950',
                       'Warszawa', 'PL', 'PLN', 1000, 230, 1230, 'plan-'||n, repeat('a', 64),
                       timestamptz '2026-01-01 00:00:00+00' + n * interval '86 seconds',
                       timestamptz '2026-01-01 00:30:00+00' + n * interval '86 seconds',
                       CASE WHEN n % 10 = 0 THEN NULL ELSE timestamptz '2026-01-01 00:05:00+00' + n * interval '86 seconds' END,
                       CASE WHEN n % 10 = 0 THEN NULL ELSE gen_random_uuid() END, 1
                FROM generate_series(1, :orders) AS n
                SQL,
            ['orders' => self::ORDERS],
        );
        $this->connection->executeStatement('ANALYZE ordering_order');
    }

    public function testTheNextHourAndTheCountOfAnHourReadThePartialIndexByPaidAt(): void
    {
        $recording = new class($this->connection->getParams(), $this->connection->getDriver(), $this->connection->getConfiguration()) extends Connection {
            /** @var list<array{string, array<int<0, max>|string, mixed>, array<int<0, max>|string, \Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type|string>}> */
            public array $statements = [];

            public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
            {
                $this->statements[] = [$sql, $params, $types];

                return parent::executeQuery($sql, $params, $types, $qcp);
            }

            public function executeStatement(string $sql, array $params = [], array $types = []): int|string
            {
                $this->statements[] = [$sql, $params, $types];

                return parent::executeStatement($sql, $params, $types);
            }
        };
        $sales = new DbalSalesHours($recording);

        $next = $sales->nextHour(new \DateTimeImmutable('2026-02-01 00:00:00+00:00'));
        self::assertNotNull($next);
        $totals = $sales->rebuild($next);
        self::assertGreaterThan(30, $totals->orderCount);

        $plans = [];
        foreach ($recording->statements as [$sql, $params, $types]) {
            if (str_contains($sql, 'FROM ordering_order')) {
                $lines = $this->connection->executeQuery('EXPLAIN '.$sql, $params, $types)->fetchFirstColumn();
                $plans[] = implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $lines));
            }
        }

        self::assertCount(2, $plans, 'the next hour and the count of the hour');
        foreach ($plans as $plan) {
            self::assertDoesNotMatchRegularExpression('/Seq Scan on ordering_order(?![_a-z])/', $plan, $plan);
            self::assertStringContainsString('ordering_order_paid_at_idx', $plan, $plan);
        }
    }
}
