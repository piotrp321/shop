<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Application\Query\GetStockLevels;
use App\Inventory\Application\Query\StockLevel;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * GetStockLevels on PostgreSQL, through the real query bus (shop_test, rolled back after each test).
 */
final class GetStockLevelsTest extends KernelTestCase
{
    private MessageBusInterface $queryBus;
    private Connection $connection;
    private StatementCounter $counter;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $this->counter = $counter;
    }

    /**
     * 48 products of 100 variants: the most a page of the store list can ask for. Every other variant has a stock.
     */
    public function test4800VariantsAreReadWithOneStatement(): void
    {
        $ids = array_map(
            static fn (mixed $id): string => \is_string($id) ? $id : throw new \LogicException('Expected a UUID.'),
            $this->connection->fetchFirstColumn('SELECT gen_random_uuid()::text FROM generate_series(1, 4800)'),
        );
        $stocked = array_values(array_filter($ids, static fn (int $i): bool => 0 === $i % 2, \ARRAY_FILTER_USE_KEY));
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at)
             SELECT CAST(id AS uuid), (n - 1) % 50, 0, n, NOW() FROM unnest(CAST(:ids AS text[])) WITH ORDINALITY AS t(id, n)',
            ['ids' => '{'.implode(',', $stocked).'}'],
        );

        $before = $this->counter->count();
        $levels = $this->ask($ids);
        $statements = $this->counter->count() - $before;

        self::assertSame(1, $statements);
        self::assertCount(4800, $levels);
        self::assertSame($ids, array_map(static fn (StockLevel $l): string => $l->variantId, $levels));
        self::assertEquals(new StockLevel($ids[0], 0, 0, 0, 'out_of_stock', 1), $levels[0]);
        self::assertEquals(new StockLevel($ids[1], 0, 0, 0, 'out_of_stock', null), $levels[1]);
        self::assertEquals(new StockLevel($ids[10], 5, 0, 5, 'low_stock', 6), $levels[10]);
        self::assertEquals(new StockLevel($ids[12], 6, 0, 6, 'in_stock', 7), $levels[12]);
    }

    public function testReadsTheStoredStateNotACachedOne(): void
    {
        $id = StockFixture::stock($this->connection, 10, 2);
        self::assertEquals(new StockLevel($id, 10, 2, 8, 'in_stock', 1), $this->ask([$id])[0]);

        $this->connection->executeStatement('UPDATE inventory_stock_item SET reserved = 9, version = 2 WHERE variant_id = ?', [$id]);

        self::assertEquals(new StockLevel($id, 10, 9, 1, 'low_stock', 2), $this->ask([$id])[0]);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<StockLevel>
     */
    private function ask(array $ids): array
    {
        $levels = $this->queryBus->dispatch(new GetStockLevels($ids))->last(HandledStamp::class)?->getResult();
        self::assertIsArray($levels);
        self::assertContainsOnlyInstancesOf(StockLevel::class, $levels);

        /** @var list<StockLevel> $levels */
        return $levels;
    }
}
