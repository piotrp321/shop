<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Persistence;

use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bounded count on PostgreSQL (shop_test): a series of numbers stands in for any table.
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

    public function testTheDefaultLimitIsTenThousand(): void
    {
        self::assertSame(10_000, BoundedCounter::DEFAULT_LIMIT);
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

    public function testTheStatementReadsAtMostTheLimitPlusOneRows(): void
    {
        $sql = BoundedCounter::sql(self::FROM, ['s.n > 5'], 10);

        $plan = $this->connection->fetchOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql);
        self::assertIsString($plan);
        $decoded = json_decode($plan, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $rowsRead = self::rowsOfScans($decoded[0]['Plan']);

        self::assertNotSame([], $rowsRead, 'The plan has a scan of the source.');
        foreach ($rowsRead as $rows) {
            self::assertLessThanOrEqual(11, $rows);
        }
    }

    public function testTheLimitMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->counter->count(self::FROM, [], [], [], 0);
    }

    /**
     * Rows each scan node of the plan handed up, one entry per node without children.
     *
     * @param array<string, mixed> $node
     *
     * @return list<int>
     */
    private static function rowsOfScans(array $node): array
    {
        $children = $node['Plans'] ?? [];
        self::assertIsArray($children);
        if ([] === $children) {
            // JSON numbers of EXPLAIN may come back as floats.
            self::assertIsNumeric($node['Actual Rows']);

            return [(int) $node['Actual Rows']];
        }

        $rows = [];
        foreach ($children as $child) {
            self::assertIsArray($child);
            $rows = [...$rows, ...self::rowsOfScans($child)];
        }

        return $rows;
    }
}
