<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Query\GetStockHistory;
use App\Inventory\Application\Query\GetStockHistoryHandler;
use App\Inventory\Application\Query\InvalidStockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryItem;
use App\Inventory\Domain\Stock\ProductNotFound;
use App\Tests\Unit\Inventory\InMemoryProductCatalog;
use App\Tests\Unit\Inventory\InMemoryStockHistoryReader;
use PHPUnit\Framework\TestCase;

final class GetStockHistoryHandlerTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';

    private InMemoryStockHistoryReader $history;
    private InMemoryProductCatalog $catalog;

    protected function setUp(): void
    {
        $this->history = new InMemoryStockHistoryReader();
        $this->catalog = new InMemoryProductCatalog();
        $this->catalog->add(self::PRODUCT, self::OTHER);
    }

    public function testAnUnknownProductIsNotFoundAndNothingIsRead(): void
    {
        $this->entry(self::UNKNOWN, 1, '2026-10-01 10:00:00+00:00', 5);

        try {
            $this->handler()(new GetStockHistory(self::UNKNOWN, null, 25));
            self::fail('Expected ProductNotFound.');
        } catch (ProductNotFound) {
            self::assertSame([], $this->history->requests);
            self::assertSame([self::UNKNOWN], $this->catalog->asked);
        }
    }

    public function testAProductWithoutHistoryHasAnEmptyPage(): void
    {
        $page = $this->handler()(new GetStockHistory(self::PRODUCT, null, 25));

        self::assertSame([], $page->items);
        self::assertNull($page->next);
    }

    public function testListsTheEntriesOfTheProductNewestFirstWithoutThoseOfOtherProducts(): void
    {
        $this->entry(self::PRODUCT, 1, '2026-10-01 10:00:00+00:00', 5);
        $this->entry(self::PRODUCT, 3, '2026-10-03 10:00:00+00:00', 7);
        $this->entry(self::PRODUCT, 2, '2026-10-02 10:00:00+00:00', 6);
        $this->entry(self::OTHER, 4, '2026-10-04 10:00:00+00:00', 9);

        $page = $this->handler()(new GetStockHistory(self::PRODUCT, null, 25));

        self::assertNull($page->next);
        self::assertSame([7, 6, 5], $this->physicalDeltas($page->items));
    }

    public function testEntriesOfTheSameSecondComeNewestFirstByTheirTimeOrderedId(): void
    {
        $this->entry(self::PRODUCT, 1, '2026-10-01 10:00:00+00:00', 100);
        $this->entry(self::PRODUCT, 2, '2026-10-01 10:00:00+00:00', 200);

        $page = $this->handler()(new GetStockHistory(self::PRODUCT, null, 25));

        self::assertSame([200, 100], $this->physicalDeltas($page->items));
    }

    public function testPagesByKeysetWithoutRepeatingOrSkippingEntries(): void
    {
        foreach (range(1, 5) as $n) {
            $this->entry(self::PRODUCT, $n, \sprintf('2026-10-0%d 10:00:00+00:00', $n), $n);
        }

        $first = $this->handler()(new GetStockHistory(self::PRODUCT, null, 2));
        self::assertNotNull($first->next);
        $second = $this->handler()(new GetStockHistory(self::PRODUCT, $first->next, 2));
        self::assertNotNull($second->next);
        $third = $this->handler()(new GetStockHistory(self::PRODUCT, $second->next, 2));

        self::assertSame([5, 4], $this->physicalDeltas($first->items));
        self::assertSame([3, 2], $this->physicalDeltas($second->items));
        self::assertSame([1], $this->physicalDeltas($third->items));
        self::assertNull($third->next);
    }

    public function testAFullLastPageHasNoNextCursor(): void
    {
        $this->entry(self::PRODUCT, 1, '2026-10-01 10:00:00+00:00', 1);
        $this->entry(self::PRODUCT, 2, '2026-10-02 10:00:00+00:00', 2);

        $page = $this->handler()(new GetStockHistory(self::PRODUCT, null, 2));

        self::assertCount(2, $page->items);
        self::assertNull($page->next);
    }

    public function testAsksTheReaderForOneEntryMoreThanThePageToKnowWhetherThereIsANextPage(): void
    {
        $this->handler()(new GetStockHistory(self::PRODUCT, null, 10));

        self::assertSame([[self::PRODUCT, null, 11]], $this->history->requests);
    }

    public function testACursorNobodyIssuedIsRejectedBeforeAnythingIsRead(): void
    {
        $this->expectException(InvalidStockHistoryCursor::class);

        try {
            $this->handler()(new GetStockHistory(self::PRODUCT, 'garbage!', 25));
        } finally {
            self::assertSame([], $this->history->requests);
            self::assertSame([], $this->catalog->asked);
        }
    }

    private function handler(): GetStockHistoryHandler
    {
        return new GetStockHistoryHandler($this->catalog, $this->history);
    }

    private function entry(string $productId, int $n, string $occurredAt, int $physicalDelta): void
    {
        $this->history->add($productId, new StockHistoryItem(
            \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n),
            new \DateTimeImmutable($occurredAt),
            'receipt',
            $physicalDelta,
            0,
            $physicalDelta,
            0,
            null,
            null,
            'receipt-'.$n,
            'staff',
            'Jan Kowalski',
        ));
    }

    /**
     * @param list<StockHistoryItem> $items
     *
     * @return list<int>
     */
    private function physicalDeltas(array $items): array
    {
        return array_map(static fn (StockHistoryItem $i): int => $i->physicalDelta, $items);
    }
}
