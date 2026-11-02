<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Persistence;

use App\Inventory\Application\Port\StockHistoryReader;
use App\Inventory\Application\Query\StockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryItem;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DbalStockHistoryReader;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The stock history reader on PostgreSQL (shop_test, rolled back after each test).
 */
final class DbalStockHistoryReaderTest extends KernelTestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    private Connection $connection;
    private StockHistoryReader $reader;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->reader = new DbalStockHistoryReader($connection);
    }

    public function testReadsEveryColumnOfAStaffReceiptWithoutTheActorId(): void
    {
        $id = (string) Uuid::v7();
        $this->insert($id, self::PRODUCT, '2026-10-31 12:30:45+02:00', 12, [
            'type' => 'receipt', 'reserved_delta' => 0, 'physical_after' => 12, 'reserved_after' => 0, 'note' => 'Dostawa', 'reference' => 'key-1',
            'source' => 'staff', 'actor_id' => 'sub-123', 'actor_name' => 'Anna Admin',
        ]);

        $items = $this->page(self::PRODUCT, null, 10);

        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame([$id, 'receipt', 12, 0, 12, 0, null, 'Dostawa', 'key-1', 'staff', 'Anna Admin'], [
            $item->id, $item->type, $item->physicalDelta, $item->reservedDelta, $item->physicalAfter, $item->reservedAfter,
            $item->reason, $item->note, $item->reference, $item->source, $item->actorName,
        ]);
        self::assertEquals(new \DateTimeImmutable('2026-10-31 10:30:45+00:00'), $item->occurredAt);
    }

    public function testACorrectionKeepsItsReasonAndASystemEntryHasNoActor(): void
    {
        $this->insert((string) Uuid::v7(), self::PRODUCT, '2026-10-01 10:00:00+00:00', -2, ['type' => 'correction', 'reason' => 'damage', 'physical_after' => 8]);
        $this->insert((string) Uuid::v7(), self::PRODUCT, '2026-10-02 10:00:00+00:00', 0, ['type' => 'reservation', 'reserved_delta' => 3, 'reserved_after' => 3, 'reference' => 'order-1', 'source' => 'system']);

        [$reservation, $correction] = $this->page(self::PRODUCT, null, 10);

        self::assertSame(['reservation', null, 'order-1', 'system', null, 3], [$reservation->type, $reservation->reason, $reservation->reference, $reservation->source, $reservation->actorName, $reservation->reservedDelta]);
        self::assertSame(['correction', 'damage', -2], [$correction->type, $correction->reason, $correction->physicalDelta]);
    }

    public function testReturnsOnlyTheEntriesOfTheProductNewestFirstAndRespectsTheLimit(): void
    {
        $this->entry(self::PRODUCT, '2026-10-01 10:00:00+00:00', 1);
        $this->entry(self::PRODUCT, '2026-10-03 10:00:00+00:00', 3);
        $this->entry(self::OTHER, '2026-10-04 10:00:00+00:00', 9);
        $this->entry(self::PRODUCT, '2026-10-02 10:00:00+00:00', 2);

        self::assertSame([3, 2, 1], $this->deltas($this->page(self::PRODUCT, null, 10)));
        self::assertSame([3, 2], $this->deltas($this->page(self::PRODUCT, null, 2)));
        self::assertSame([], $this->page('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62', null, 10));
    }

    public function testEntriesOfTheSameSecondComeLaterFirstAndKeysetPagingSplitsThemWithoutLoss(): void
    {
        $same = '2026-10-01 10:00:00+00:00';
        // Time-ordered ids, as the repository writes them: a smaller id is an earlier entry.
        $this->entry(self::PRODUCT, '2026-10-01 09:00:00+00:00', 1);
        $this->entry(self::PRODUCT, $same, 2, '0192f0c4-0000-7000-8000-000000000001');
        $this->entry(self::PRODUCT, $same, 3, '0192f0c4-0000-7000-8000-000000000002');
        $this->entry(self::PRODUCT, $same, 4, '0192f0c4-0000-7000-8000-000000000003');
        $this->entry(self::PRODUCT, '2026-10-02 09:00:00+00:00', 5);

        self::assertSame([5, 4, 3, 2, 1], $this->deltas($this->page(self::PRODUCT, null, 10)));

        $seen = [];
        $after = null;
        do {
            $items = $this->page(self::PRODUCT, $after, 2);
            array_push($seen, ...$this->deltas($items));
            $after = [] === $items ? null : StockHistoryCursor::after($items[\count($items) - 1]);
        } while ([] !== $items);

        self::assertSame([5, 4, 3, 2, 1], $seen);
    }

    public function testReadsAPageWithOneStatement(): void
    {
        $this->entry(self::PRODUCT, '2026-10-01 10:00:00+00:00', 1);
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);

        $before = $counter->count();
        $this->page(self::PRODUCT, null, 10);

        self::assertSame(1, $counter->count() - $before);
    }

    public function testALimitBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->page(self::PRODUCT, null, 0);
    }

    private function entry(string $productId, string $occurredAt, int $delta, ?string $id = null): void
    {
        $this->insert($id ?? (string) Uuid::v7(), $productId, $occurredAt, $delta);
    }

    /**
     * @param array<string, mixed> $columns more columns of the movement row
     */
    private function insert(string $id, string $productId, string $occurredAt, int $physicalDelta, array $columns = []): void
    {
        $columns += [
            'type' => 'receipt', 'reserved_delta' => 0, 'physical_after' => $physicalDelta, 'reserved_after' => 0,
            'reason' => null, 'note' => null, 'reference' => null, 'source' => 'seed', 'actor_id' => null, 'actor_name' => null,
        ];
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_movement (id, product_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reason, note, reference, source, actor_id, actor_name, occurred_at) '
            .'VALUES (:id, :product_id, :type, :physical_delta, :reserved_delta, :physical_after, :reserved_after, :reason, :note, :reference, :source, :actor_id, :actor_name, :occurred_at)',
            ['id' => $id, 'product_id' => $productId, 'physical_delta' => $physicalDelta, 'occurred_at' => $occurredAt] + $columns,
        );
    }

    /**
     * @return list<StockHistoryItem>
     */
    private function page(string $productId, ?StockHistoryCursor $after, int $limit): array
    {
        return $this->reader->page(ProductId::fromString($productId), $after, $limit);
    }

    /**
     * @param list<StockHistoryItem> $items
     *
     * @return list<int>
     */
    private function deltas(array $items): array
    {
        return array_map(static fn (StockHistoryItem $item): int => $item->physicalDelta, $items);
    }
}
