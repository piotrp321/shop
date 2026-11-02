<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Query\ListBackofficeStock;
use App\Inventory\Application\Query\ListBackofficeStockHandler;
use App\Inventory\Domain\Stock\ProductId;
use App\Tests\Unit\Inventory\InMemoryProductDirectory;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use PHPUnit\Framework\TestCase;

final class ListBackofficeStockHandlerTest extends TestCase
{
    private const string IN_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string LOW_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string UNSTORED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';
    private const string EMPTY_STORED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';

    private InMemoryStockItemRepository $stock;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->stock->store(self::IN_STOCK, 10, 4, version: 7);
        $this->stock->store(self::LOW_STOCK, 9, 4, version: 2);
        $this->stock->store(self::EMPTY_STORED, 0, 0, version: 0);
    }

    public function testListsTheProductsOfThePageWithTheirStockAndTheCursorOfTheNextPage(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::LOW_STOCK, 'Bidon'), $this->product(self::IN_STOCK, 'Plecak')], 'next-cursor');

        $list = $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertSame('next-cursor', $list->next);
        self::assertCount(2, $list->items);
        [$low, $in] = $list->items;
        self::assertSame([self::LOW_STOCK, 'Bidon'], [$low->productId, $low->name]);
        self::assertNotNull($low->stock);
        self::assertSame([9, 4, 5, 'low_stock', 2], [$low->stock->physical, $low->stock->reserved, $low->stock->available, $low->stock->level, $low->stock->version]);
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00+00:00'), $low->stock->updatedAt);
        self::assertNotNull($in->stock);
        self::assertSame([10, 4, 6, 'in_stock', 7], [$in->stock->physical, $in->stock->reserved, $in->stock->available, $in->stock->level, $in->stock->version]);
    }

    public function testAProductWithoutAStoredStockHasNoStock(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::UNSTORED, 'Żel')]);

        $list = $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertNull($list->next);
        self::assertSame(self::UNSTORED, $list->items[0]->productId);
        self::assertNull($list->items[0]->stock);
    }

    public function testAnEmptyStockNeverChangedEqualsNoStock(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::EMPTY_STORED, 'Żel')]);

        $list = $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertNull($list->items[0]->stock);
    }

    public function testStocksOfThePageAreReadWithOneCall(): void
    {
        $directory = new InMemoryProductDirectory([
            $this->product(self::LOW_STOCK, 'A'),
            $this->product(self::UNSTORED, 'B'),
            $this->product(self::IN_STOCK, 'C'),
        ]);

        $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertSame([[self::LOW_STOCK, self::UNSTORED, self::IN_STOCK]], $this->stock->manyReads);
    }

    public function testAnEmptyPageReadsNoStocks(): void
    {
        $list = $this->handler(new InMemoryProductDirectory())(new ListBackofficeStock('xyz', null, null, 25));

        self::assertSame([], $list->items);
        self::assertNull($list->next);
        self::assertSame([], $this->stock->manyReads);
    }

    public function testPassesTheFiltersTheCursorAndThePageSizeToTheDirectory(): void
    {
        $directory = new InMemoryProductDirectory();

        $this->handler($directory)(new ListBackofficeStock('bie', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', 'cursor', 10));

        self::assertSame([['bie', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', 'cursor', 10]], $directory->requests);
    }

    public function testAnUnknownCategoryIsNotSwallowed(): void
    {
        $missing = new InMemoryProductDirectory();
        $missing->categoryExists = false;

        try {
            $this->handler($missing)(new ListBackofficeStock(null, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', null, 25));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
            self::assertSame([], $this->stock->manyReads);
        }
    }

    public function testAnInvalidCursorIsNotSwallowed(): void
    {
        $invalid = new InMemoryProductDirectory();
        $invalid->cursorValid = false;
        $this->expectException(InvalidStockListCursor::class);

        $this->handler($invalid)(new ListBackofficeStock(null, null, 'garbage', 25));
    }

    private function handler(InMemoryProductDirectory $directory): ListBackofficeStockHandler
    {
        return new ListBackofficeStockHandler($directory, $this->stock);
    }

    private function product(string $id, string $name): DirectoryProduct
    {
        return new DirectoryProduct(ProductId::fromString($id), $name);
    }
}
