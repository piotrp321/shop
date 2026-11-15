<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Persistence;

use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bounded count on PostgreSQL (shop_test): a series of numbers stands in for a table in the tests of the result, a real (temporary) table in the plan test.
 */
final class BoundedCounterTest extends KernelTestCase
{
    private const string FROM = 'generate_series(1, 50) AS s(n)';

    private Connection $connection;
    private BoundedCounter $counter;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->counter = new BoundedCounter($connection);
    }

    public function testCountsExactlyUnderTheLimit(): void
    {
        $count = $this->counter->count(self::FROM, ['s.n % 2 = 0'], [], [], 40);

        self::assertSame(25, $count->value);
        self::assertFalse($count->capped);
    }

    public function testCountsAllRowsWithoutAnyCondition(): void
    {
        $count = $this->counter->count(self::FROM, [], [], [], 50);

        self::assertSame(50, $count->value);
        self::assertFalse($count->capped);
    }

    public function testBindsTheParametersOfTheConditionsWithTheirTypes(): void
    {
        $count = $this->counter->count(self::FROM, ['s.n > :from', 's.n <= :to'], ['from' => 10, 'to' => 20], ['from' => ParameterType::INTEGER, 'to' => ParameterType::INTEGER], 40);

        self::assertSame(10, $count->value);
        self::assertFalse($count->capped);
    }

    public function testStopsAtTheLimitAndSaysSo(): void
    {
        $count = $this->counter->count(self::FROM, ['s.n > 5'], [], [], 10);

        self::assertSame(10, $count->value);
        self::assertTrue($count->capped);
    }

    public function testNoMatchIsAnExactZero(): void
    {
        $count = $this->counter->count(self::FROM, ['s.n > 500'], [], [], 10);

        self::assertSame(0, $count->value);
        self::assertFalse($count->capped);
    }

    public function testTheStatementStopsTheScanOfARealTableAfterTheLimitPlusOneRows(): void
    {
        // The scan of a table is the node that matters: a Function Scan of generate_series materializes every row, so
        // the series above cannot show whether the statement stops reading.
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('CREATE TEMPORARY TABLE bounded_counter_rows (n integer NOT NULL, label text NOT NULL) ON COMMIT DROP');
            $this->connection->executeStatement("INSERT INTO bounded_counter_rows SELECT n, 'row '||n FROM generate_series(1, 5000) AS s(n)");
            $this->connection->executeStatement('ANALYZE bounded_counter_rows');

            $sql = BoundedCounter::sql('bounded_counter_rows r', ['r.n > 5', "r.label <> ''"], 10);
            $plan = $this->connection->fetchOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql);
        } finally {
            $this->connection->rollBack();
        }

        self::assertIsString($plan);
        $decoded = json_decode($plan, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]);
        self::assertIsArray($decoded[0]['Plan']);
        $nodes = self::nodes($decoded[0]['Plan']);

        self::assertContains('Limit', array_column($nodes, 'Node Type'), 'The statement has a Limit node.');
        $scans = array_values(array_filter($nodes, static fn (array $node): bool => 'bounded_counter_rows' === ($node['Relation Name'] ?? null)));
        self::assertCount(1, $scans, 'The plan scans the table once.');
        // The filter drops no row of this table, so 4 995 rows match: a scan not stopped by the Limit would hand up all of them.
        self::assertIsNumeric($scans[0]['Actual Rows']);
        self::assertLessThanOrEqual(11, (int) $scans[0]['Actual Rows']);
    }

    public function testTheLimitMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->counter->count(self::FROM, [], [], [], 0);
    }

    /**
     * Every node of the plan, the node itself before its children.
     *
     * @param array<mixed> $node
     *
     * @return list<array<mixed>>
     */
    private static function nodes(array $node): array
    {
        $children = $node['Plans'] ?? [];
        self::assertIsArray($children);

        $nodes = [$node];
        foreach ($children as $child) {
            self::assertIsArray($child);
            $nodes = [...$nodes, ...self::nodes($child)];
        }

        return $nodes;
    }
}
